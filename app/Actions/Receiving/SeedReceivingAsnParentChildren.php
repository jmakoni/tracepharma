<?php

namespace App\Actions\Receiving;

use App\Models\Epcis\Epc;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\InboundExpectedLine;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Support\Receiving\InboundExpectedLineClaims;
use App\Support\Receiving\ResolveInboundAggregationChildEpcs;
use Illuminate\Support\Facades\Schema;

/**
 * Seed (and optionally auto-confirm) aggregation children under a confirmed ASN parent.
 *
 * Upgrades pre-existing unexpected lines that are hierarchy children so they are not
 * stuck unexpected after insertOrIgnore skips them.
 */
final class SeedReceivingAsnParentChildren
{
    public function __construct(
        private readonly ResolveInboundAggregationChildEpcs $resolveInboundAggregationChildEpcs,
    ) {}

    private static ?bool $hasExpectedLinesTable = null;

    private static ?bool $hasSessionShipmentColumn = null;

    private static ?bool $hasDocumentShipmentColumn = null;

    /**
     * @return array{
     *     child_epc_ids: list<int>,
     *     confirmed_children: int,
     *     skipped_quarantined: int,
     *     expected_child_count: int,
     *     parent_expected_children: int,
     *     parent_confirmed_children: int
     * }
     */
    public function handle(
        ReceivingSession $session,
        Epc $parentEpc,
        ?int $userId = null,
        bool $autoConfirmChildren = false,
    ): array {
        $now = now();
        $childEpcIds = $this->resolveInboundAggregationChildEpcs->childEpcIdsForParent($session, $parentEpc);
        $parentExpected = count($childEpcIds);

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
                'parent_expected_children' => 0,
                'parent_confirmed_children' => 0,
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
            $skippedQuarantined = $parentExpected - count($confirmableChildIds);
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

        foreach (array_chunk($childRows, 200) as $chunk) {
            ReceivingScanLine::query()->insertOrIgnore($chunk);
        }

        $this->upsertShipmentExpectedChildren(
            $session,
            $parentEpc,
            $childEpcIds,
            $autoConfirmChildren,
            $userId,
        );

        // Hierarchy children scanned before the parent were logged unexpected;
        // insertOrIgnore skipped them — promote onto the expected/confirmed path in bulk.
        $unexpectedEpcIds = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->whereIn('epc_id', $childEpcIds)
            ->where('status', 'unexpected')
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($unexpectedEpcIds !== []) {
            $quarantinedSet = array_fill_keys(
                QuarantineHold::query()
                    ->open()
                    ->whereIn('epc_id', $unexpectedEpcIds)
                    ->pluck('epc_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
                true,
            );

            $confirmUnexpectedIds = [];
            $expectUnexpectedIds = [];
            $scannedUnexpectedIds = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->whereIn('epc_id', $unexpectedEpcIds)
                ->where('status', 'unexpected')
                ->whereNotNull('scan_raw')
                ->where('scan_raw', '!=', '')
                ->pluck('epc_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $scannedSet = array_fill_keys($scannedUnexpectedIds, true);

            foreach ($unexpectedEpcIds as $epcId) {
                $mayConfirm = isset($scannedSet[$epcId]) && ! isset($quarantinedSet[$epcId]);
                $autoConfirm = $autoConfirmChildren && isset($confirmableSet[$epcId]) && ! isset($quarantinedSet[$epcId]);
                if ($autoConfirm || $mayConfirm) {
                    $confirmUnexpectedIds[] = $epcId;
                } else {
                    $expectUnexpectedIds[] = $epcId;
                }
            }

            if ($confirmUnexpectedIds !== []) {
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->getKey())
                    ->whereIn('epc_id', $confirmUnexpectedIds)
                    ->where('status', 'unexpected')
                    ->update([
                        'line_role' => 'child',
                        'parent_epc_id' => $parentEpc->getKey(),
                        'status' => 'confirmed',
                        'confirmed_at' => $now,
                        'confirmed_by' => $userId,
                        'updated_at' => $now,
                    ]);
            }

            if ($expectUnexpectedIds !== []) {
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->getKey())
                    ->whereIn('epc_id', $expectUnexpectedIds)
                    ->where('status', 'unexpected')
                    ->update([
                        'line_role' => 'child',
                        'parent_epc_id' => $parentEpc->getKey(),
                        'status' => 'expected',
                        'confirmed_at' => null,
                        'confirmed_by' => null,
                        'updated_at' => $now,
                    ]);
            }
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

        $nowConfirmedForParent = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('line_role', 'child')
            ->where('parent_epc_id', $parentEpc->getKey())
            ->whereIn('epc_id', $childEpcIds)
            ->where('status', 'confirmed')
            ->count();

        $confirmedChildren = max(0, $nowConfirmedForParent - $alreadyConfirmedForParent);

        $expectedChildCount = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('line_role', 'child')
            ->count();

        return [
            'child_epc_ids' => $childEpcIds,
            'confirmed_children' => $confirmedChildren,
            'skipped_quarantined' => $skippedQuarantined,
            'expected_child_count' => $expectedChildCount,
            'parent_expected_children' => $parentExpected,
            'parent_confirmed_children' => $nowConfirmedForParent,
        ];
    }

    /**
     * @param  list<int>  $childEpcIds
     */
    private function upsertShipmentExpectedChildren(
        ReceivingSession $session,
        Epc $parentEpc,
        array $childEpcIds,
        bool $autoConfirmChildren = false,
        ?int $userId = null,
    ): void {
        if ($childEpcIds === []
            || ! $this->hasExpectedLinesTable()
            || ! $this->hasSessionShipmentColumn()
            || $session->inbound_shipment_id === null) {
            return;
        }

        $shipmentId = (int) $session->inbound_shipment_id;
        $parentEpcId = (int) $parentEpc->getKey();
        $sessionId = (int) $session->getKey();
        $uniqueChildIds = array_values(array_unique(array_map('intval', $childEpcIds)));
        $now = now();

        $existingByEpc = InboundExpectedLine::query()
            ->where('inbound_shipment_id', $shipmentId)
            ->whereIn('epc_id', $uniqueChildIds)
            ->get()
            ->keyBy(fn (InboundExpectedLine $line): int => (int) $line->epc_id);

        $insertRows = [];
        $setParentIds = [];
        $setChildRoleIds = [];
        $setSourceIds = [];
        $setClaimIds = [];
        $confirmIds = [];

        foreach ($uniqueChildIds as $childEpcId) {
            $existing = $existingByEpc->get($childEpcId);

            if ($existing === null) {
                $row = [
                    'inbound_shipment_id' => $shipmentId,
                    'epc_id' => $childEpcId,
                    'parent_epc_id' => $parentEpcId,
                    'line_role' => 'child',
                    'status' => $autoConfirmChildren ? 'confirmed' : 'expected',
                    'source' => 'epcis_aggregation',
                    'claimed_receiving_session_id' => $autoConfirmChildren ? null : $sessionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if ($autoConfirmChildren) {
                    $row['confirmed_at'] = $now;
                    $row['confirmed_by'] = $userId;
                    $row['confirmed_receiving_session_id'] = $sessionId;
                }
                $insertRows[] = $row;

                continue;
            }

            if (in_array($existing->status, ['confirmed', 'cancelled', 'unexpected'], true)) {
                continue;
            }

            $id = (int) $existing->getKey();

            if ($existing->parent_epc_id === null) {
                $setParentIds[] = $id;
            }
            if ($existing->line_role !== 'child') {
                $setChildRoleIds[] = $id;
            }
            if ($existing->source === null) {
                $setSourceIds[] = $id;
            }
            if ($autoConfirmChildren) {
                $confirmIds[] = $id;
            } elseif ($existing->claimed_receiving_session_id === null
                || (int) $existing->claimed_receiving_session_id === $sessionId) {
                $setClaimIds[] = $id;
            }
        }

        if ($insertRows !== []) {
            InboundExpectedLine::query()->insertOrIgnore($insertRows);
        }

        if ($setParentIds !== []) {
            InboundExpectedLine::query()
                ->whereIn('id', $setParentIds)
                ->update(['parent_epc_id' => $parentEpcId, 'updated_at' => $now]);
        }
        if ($setChildRoleIds !== []) {
            InboundExpectedLine::query()
                ->whereIn('id', $setChildRoleIds)
                ->update(['line_role' => 'child', 'updated_at' => $now]);
        }
        if ($setSourceIds !== []) {
            InboundExpectedLine::query()
                ->whereIn('id', $setSourceIds)
                ->update(['source' => 'epcis_aggregation', 'updated_at' => $now]);
        }
        if ($setClaimIds !== []) {
            InboundExpectedLine::query()
                ->whereIn('id', $setClaimIds)
                ->update(['claimed_receiving_session_id' => $sessionId, 'updated_at' => $now]);
        }
        if ($confirmIds !== []) {
            InboundExpectedLine::query()
                ->whereIn('id', $confirmIds)
                ->update([
                    'status' => 'confirmed',
                    'confirmed_at' => $now,
                    'confirmed_by' => $userId,
                    'confirmed_receiving_session_id' => $sessionId,
                    'claimed_receiving_session_id' => null,
                    'updated_at' => $now,
                ]);
        }

        if (! $autoConfirmChildren) {
            InboundExpectedLineClaims::claimExpectedChildren($session, $uniqueChildIds);
        } elseif ($insertRows !== []) {
            // insertOrIgnore may have skipped rows Seed thought were missing — claim/confirm those.
            InboundExpectedLine::query()
                ->where('inbound_shipment_id', $shipmentId)
                ->whereIn('epc_id', $uniqueChildIds)
                ->where('status', 'expected')
                ->update([
                    'parent_epc_id' => $parentEpcId,
                    'line_role' => 'child',
                    'status' => 'confirmed',
                    'confirmed_at' => $now,
                    'confirmed_by' => $userId,
                    'confirmed_receiving_session_id' => $sessionId,
                    'claimed_receiving_session_id' => null,
                    'updated_at' => $now,
                ]);
        }
    }

    private function hasExpectedLinesTable(): bool
    {
        return self::$hasExpectedLinesTable ??= Schema::hasTable('inbound_expected_lines');
    }

    private function hasSessionShipmentColumn(): bool
    {
        return self::$hasSessionShipmentColumn ??= Schema::hasColumn('receiving_sessions', 'inbound_shipment_id');
    }

    private function hasDocumentShipmentColumn(): bool
    {
        return self::$hasDocumentShipmentColumn ??= Schema::hasColumn('epcis_documents', 'inbound_shipment_id');
    }
}
