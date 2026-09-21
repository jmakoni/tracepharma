<?php

declare(strict_types=1);

namespace App\Actions\Disposition;

use App\Actions\Labeling\PersistAuthoredSsccEpcis;
use App\Actions\Outbound\GenerateDispositionEpcisDocument;
use App\Actions\Outbound\GenerateDispositionObjectEvent;
use App\Enums\EpcisAuthoredKind;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class EmitInspectingEpcis
{
    public function __construct(
        private readonly GenerateDispositionEpcisDocument $documentGenerator,
        private readonly PersistAuthoredSsccEpcis $persist,
    ) {}

    /**
     * Authored inspecting ObjectEvent (R1.3 CBV). Self-authored inspect docs are R1.3-shaped.
     *
     * @param  list<int>  $epcIds
     * @param  array{sync?: bool, dispatch?: bool}  $options
     * @return array{document: EpcisDocument|null, inspected_count: int, path: string|null}
     */
    public function handle(array $epcIds, int $siteId, array $options = []): array
    {
        $epcIds = array_values(array_unique(array_filter(
            array_map(intval(...), $epcIds),
            fn (int $id): bool => $id > 0,
        )));

        if ($epcIds === [] || $siteId <= 0) {
            return ['document' => null, 'inspected_count' => 0, 'path' => null];
        }

        $epcs = Epc::query()->whereIn('id', $epcIds)->get();
        $uris = [];
        foreach ($epcs as $epc) {
            if (filled($epc->epc_uri)) {
                $uris[] = (string) $epc->epc_uri;
            }
        }

        if ($uris === []) {
            throw new InvalidArgumentException('No EPC URIs available for inspecting EPCIS.');
        }

        $xml = $this->documentGenerator->execute(
            $uris,
            GenerateDispositionObjectEvent::KIND_INSPECTING,
            $siteId,
        );

        $uuid = (string) Str::uuid();
        $path = 'epcis/outbound/inspecting-'.$uuid.'.xml';

        $document = $this->persist->handle($xml, $path, [
            'authored_kind' => EpcisAuthoredKind::Inspecting,
            'original_filename' => 'inspecting-'.$uuid.'.xml',
            'notes' => 'Generated inspecting EPCIS for '.count($uris).' EPC(s).',
            'ship_from_site_id' => $siteId,
            'sync' => (bool) ($options['sync'] ?? true),
            'dispatch' => (bool) ($options['dispatch'] ?? false),
        ]);

        return [
            'document' => $document,
            'inspected_count' => count($uris),
            'path' => $path,
        ];
    }
}
