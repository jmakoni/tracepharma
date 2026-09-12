<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Models\ConnectionGoLiveChecklist;
use App\Models\Epcis\EpcisDocument;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Models\User;

/**
 * Renders a Markdown go-live evidence pack for a connection: checklist state,
 * sign-off, break-glass usage, and traffic summary. Suitable for IOQ-style
 * inspection documentation.
 */
class GoLiveEvidencePack
{
    public function __construct(private readonly GoLiveChecklistEvaluator $evaluator) {}

    public function render(Tenant $tenant, OutboundConnection|InboundConnection $connection): string
    {
        $type = $connection instanceof OutboundConnection
            ? ConnectionGoLiveChecklist::TYPE_OUTBOUND
            : ConnectionGoLiveChecklist::TYPE_INBOUND;

        $checklist = ConnectionGoLiveChecklist::forConnection($type, (int) $connection->getKey());
        $steps = $this->evaluator->evaluate($connection);
        $done = count(array_filter($steps, static fn (array $s): bool => $s['done']));

        $docColumn = $connection instanceof OutboundConnection ? 'outbound_connection_id' : 'inbound_connection_id';
        $docCount = EpcisDocument::query()->where($docColumn, $connection->getKey())->count();
        $validatedCount = EpcisDocument::query()->where($docColumn, $connection->getKey())->where('status', 'validated')->count();

        $signOffUser = $checklist->signed_off_by !== null
            ? User::query()->find($checklist->signed_off_by)
            : null;

        $lines = [
            '# Go-live evidence pack',
            '',
            '- Tenant: '.$tenant->name.' ('.$tenant->getKey().')',
            '- Connection: '.$connection->name.' (#'.(int) $connection->getKey().', '.$type.')',
            '- Transport: '.(string) ($connection->transport?->value ?? $connection->transport ?? 'n/a'),
            '- Generated: '.now()->toIso8601String(),
            '',
            '## Checklist ('.$done.'/'.count($steps).' complete)',
            '',
        ];

        foreach ($steps as $step) {
            $lines[] = sprintf(
                '- [%s] **%s** (%s) — %s',
                $step['done'] ? 'x' : ' ',
                $step['label'],
                $step['source'],
                $step['detail'],
            );
        }

        $lines[] = '';
        $lines[] = '## Sign-off';
        $lines[] = '';

        if ($checklist->isSignedOff()) {
            $lines[] = '- Signed off by: '.($signOffUser?->name ?? 'user #'.$checklist->signed_off_by);
            $lines[] = '- Signed off at: '.$checklist->signed_off_at->toIso8601String();
        } else {
            $lines[] = '- Not signed off.';
        }

        if ($checklist->break_glass_reason !== null) {
            $lines[] = '- Break-glass to live used. Reason: '.$checklist->break_glass_reason;
        }

        $lines[] = '';
        $lines[] = '## Traffic summary';
        $lines[] = '';
        $lines[] = '- Documents through this connection: '.$docCount;
        $lines[] = '- Validated documents: '.$validatedCount;

        if ($connection instanceof OutboundConnection) {
            $state = $connection->conformanceState();
            $lines[] = '- Conformance state: '.$state->value;
        }

        return implode("\n", $lines)."\n";
    }
}
