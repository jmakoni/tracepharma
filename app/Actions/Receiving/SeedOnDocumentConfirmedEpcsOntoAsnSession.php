<?php

namespace App\Actions\Receiving;

use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use Illuminate\Support\Facades\DB;

/**
 * Before strict Match ASN copy: ensure confirmed scan-first EPCs that appear on the
 * ASN document are expected lines (and seed hierarchy children under SSCC parents)
 * so on-manifest units can copy while off-manifest units stay skipped.
 */
final class SeedOnDocumentConfirmedEpcsOntoAsnSession
{
    public function __construct(
        private readonly SeedReceivingAsnParentChildren $seedReceivingAsnParentChildren,
    ) {}

    public function handle(
        ReceivingSession $from,
        ReceivingSession $to,
        EpcisDocument $document,
        ?int $userId = null,
    ): void {
        $to = $to->fresh() ?? $to;
        if (! $to->isInboundAsn()) {
            return;
        }

        $documentEpcIds = DB::table('event_epcs')
            ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
            ->where('epcis_events.document_id', $document->getKey())
            ->distinct()
            ->pluck('event_epcs.epc_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($documentEpcIds === []) {
            return;
        }

        $confirmedLines = ReceivingScanLine::query()
            ->where('receiving_session_id', $from->getKey())
            ->where('status', 'confirmed')
            ->whereIn('epc_id', $documentEpcIds)
            ->with('epc')
            ->orderByRaw("CASE WHEN line_role = 'parent' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get();

        if ($confirmedLines->isEmpty()) {
            return;
        }

        $now = now();

        foreach ($confirmedLines as $sourceLine) {
            $epc = $sourceLine->epc ?? Epc::query()->find($sourceLine->epc_id);
            if ($epc === null) {
                continue;
            }

            $lineRole = $sourceLine->line_role
                ?: ($epc->epc_type === 'sscc' ? 'parent' : 'child');

            ReceivingScanLine::query()->insertOrIgnore([[
                'receiving_session_id' => $to->getKey(),
                'epc_id' => $epc->getKey(),
                'parent_epc_id' => $sourceLine->parent_epc_id,
                'line_role' => $lineRole,
                'status' => 'expected',
                'created_at' => $now,
                'updated_at' => $now,
            ]]);

            if ($lineRole === 'parent' || $epc->epc_type === 'sscc') {
                $seeded = $this->seedReceivingAsnParentChildren->handle(
                    $to->fresh(),
                    $epc,
                    $userId,
                    autoConfirmChildren: false,
                );

                $to->forceFill([
                    'expected_child_count' => $seeded['expected_child_count'],
                ])->save();
            }
        }

        $expectedParents = ReceivingScanLine::query()
            ->where('receiving_session_id', $to->getKey())
            ->where('line_role', 'parent')
            ->count();
        $expectedChildren = ReceivingScanLine::query()
            ->where('receiving_session_id', $to->getKey())
            ->where('line_role', 'child')
            ->count();

        $to->forceFill([
            'expected_parent_count' => max((int) $to->expected_parent_count, $expectedParents),
            'expected_child_count' => $expectedChildren,
        ])->save();
    }
}
