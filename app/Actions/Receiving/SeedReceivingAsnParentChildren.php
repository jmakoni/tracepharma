<?php

namespace App\Actions\Receiving;

use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use Illuminate\Support\Facades\Schema;

/**
 * Seed (and optionally auto-confirm) aggregation children under a confirmed ASN parent.
 *
 * Upgrades pre-existing unexpected lines that are hierarchy children so they are not
 * stuck unexpected after insertOrIgnore skips them.
 */
final class SeedReceivingAsnParentChildren
{
    /**
     * @return array{
     *     child_epc_ids: list<int>,
     *     confirmed_children: int,
     *     skipped_quarantined: int,
     *     expected_child_count: int
     * }
     */
    public function handle(
        ReceivingSession $session,
        Epc $parentEpc,
        ?int $userId = null,
        bool $autoConfirmChildren = false,
    ): array {
        $now = now();
        $documentId = $session->epcis_document_id;

        if ($documentId === null) {
            return [
                'child_epc_ids' => [],
                'confirmed_children' => 0,
                'skipped_quarantined' => 0,
                'expected_child_count' => (int) $session->expected_child_count,
            ];
        }

        $document = EpcisDocument::query()->find($documentId);

        $childEpcIds = AggregationLink::query()
            ->where('parent_epc_id', $parentEpc->getKey())
            ->whereNull('valid_to')
            ->whereIn('established_by_event_id', function ($query) use ($documentId, $document): void {
                $query->select('id')
                    ->from('epcis_events')
                    ->where('document_id', $documentId);

                if (
                    $document !== null
                    && Schema::hasColumn('epcis_events', 'ingest_generation')
                    && Schema::hasColumn('epcis_documents', 'ingest_generation')
                    && filled($document->getAttribute('ingest_generation'))
                ) {
                    $query->where('ingest_generation', $document->getAttribute('ingest_generation'));
                }
            })
            ->pluck('child_epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($childEpcIds === []) {
            $expectedChildCount = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('line_role', 'child')
                ->count();

            return [
                'child_epc_ids' => [],
                'confirmed_children' => 0,
                'skipped_quarantined' => 0,
                'expected_child_count' => $expectedChildCount,
            ];
        }

        $confirmableChildIds = $childEpcIds;
        $skippedQuarantined = 0;

        if ($autoConfirmChildren) {
            $quarantinedChildIds = QuarantineHold::query()
                ->open()
                ->whereIn('epc_id', $childEpcIds)
                ->pluck('epc_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $confirmableChildIds = array_values(array_diff($childEpcIds, $quarantinedChildIds));
            $skippedQuarantined = count($childEpcIds) - count($confirmableChildIds);
        }

        $confirmableSet = array_fill_keys($confirmableChildIds, true);

        $alreadyConfirmedForParent = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('line_role', 'child')
            ->where('parent_epc_id', $parentEpc->getKey())
            ->whereIn('epc_id', $childEpcIds)
            ->where('status', 'confirmed')
            ->count();

        $childRows = [];
        foreach ($childEpcIds as $childEpcId) {
            $autoConfirm = $autoConfirmChildren && isset($confirmableSet[$childEpcId]);

            $childRows[] = [
                'receiving_session_id' => $session->getKey(),
                'epc_id' => $childEpcId,
                'parent_epc_id' => $parentEpc->getKey(),
                'line_role' => 'child',
                'status' => $autoConfirm ? 'confirmed' : 'expected',
                'confirmed_at' => $autoConfirm ? $now : null,
                'confirmed_by' => $autoConfirm ? $userId : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($childRows !== []) {
            ReceivingScanLine::query()->insertOrIgnore($childRows);
        }

        // Hierarchy children scanned before the parent were logged unexpected;
        // insertOrIgnore skipped them — promote onto the expected/confirmed path.
        $unexpectedLines = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->whereIn('epc_id', $childEpcIds)
            ->where('status', 'unexpected')
            ->get();

        $quarantinedSet = [];
        if ($unexpectedLines->isNotEmpty()) {
            $quarantinedSet = array_fill_keys(
                QuarantineHold::query()
                    ->open()
                    ->whereIn('epc_id', $unexpectedLines->pluck('epc_id')->all())
                    ->pluck('epc_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
                true,
            );
        }

        foreach ($unexpectedLines as $unexpectedLine) {
            $epcId = (int) $unexpectedLine->epc_id;
            $alreadyScanned = filled($unexpectedLine->scan_raw);
            $mayConfirm = $alreadyScanned && ! isset($quarantinedSet[$epcId]);
            $autoConfirm = $autoConfirmChildren && isset($confirmableSet[$epcId]);
            $confirmNow = $autoConfirm || $mayConfirm;

            $unexpectedLine->forceFill([
                'line_role' => 'child',
                'parent_epc_id' => $parentEpc->getKey(),
                'status' => $confirmNow ? 'confirmed' : 'expected',
                'confirmed_at' => $confirmNow ? ($unexpectedLine->confirmed_at ?? $now) : null,
                'confirmed_by' => $confirmNow ? ($unexpectedLine->confirmed_by ?? $userId) : null,
                'updated_at' => $now,
            ])->save();
        }

        $confirmedChildren = 0;

        if ($autoConfirmChildren && $confirmableChildIds !== []) {
            ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('line_role', 'child')
                ->where('parent_epc_id', $parentEpc->getKey())
                ->where('status', 'expected')
                ->whereIn('epc_id', $confirmableChildIds)
                ->update([
                    'status' => 'confirmed',
                    'confirmed_at' => $now,
                    'confirmed_by' => $userId,
                    'updated_at' => $now,
                ]);
        }

        if ($childEpcIds !== []) {
            $nowConfirmedForParent = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('line_role', 'child')
                ->where('parent_epc_id', $parentEpc->getKey())
                ->whereIn('epc_id', $childEpcIds)
                ->where('status', 'confirmed')
                ->count();

            $confirmedChildren = max(0, $nowConfirmedForParent - $alreadyConfirmedForParent);
        }

        $expectedChildCount = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('line_role', 'child')
            ->count();

        return [
            'child_epc_ids' => $childEpcIds,
            'confirmed_children' => $confirmedChildren,
            'skipped_quarantined' => $skippedQuarantined,
            'expected_child_count' => $expectedChildCount,
        ];
    }
}
