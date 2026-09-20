<?php

namespace App\Actions\Receiving;

use App\Models\Epcis\Epc;
use App\Models\Receiving\InboundExpectedLine;
use App\Models\Receiving\InboundShipment;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Receiving\ReceivingPackShape;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\Receiving\ResolveInboundAggregationChildEpcs;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Undo a single floor scan line: unconfirm an ASN parent (and drop its children)
 * or delete an unexpected line. Blocked once the session is completed or receiving
 * EPCIS events have already been generated.
 */
final class UnconfirmReceivingScanLine
{
    public function __construct(
        private readonly CompensateTransferReceiveLine $compensateTransferReceiveLine,
    ) {}

    public function handle(
        ReceivingScanLine $line,
        ?int $actorId = null,
        bool $allowChildUnderConfirmedParent = false,
    ): ReceivingSession {
        if (! JobRoleAccess::allows(Permissions::NavReceive)) {
            throw new DomainException('Receiving is not authorized for your job role.');
        }

        $session = $line->receivingSession ?? ReceivingSession::query()->find($line->receiving_session_id);
        if ($session instanceof ReceivingSession) {
            $user = auth()->user();
            if ($user instanceof User) {
                $this->assertCanAccessSessionSite($user, $session);
            }
        }

        $lineId = (int) $line->getKey();

        $result = DB::transaction(function () use ($line, $allowChildUnderConfirmedParent): ReceivingSession {
            $line = ReceivingScanLine::query()
                ->whereKey($line->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $session = ReceivingSession::query()
                ->whereKey($line->receiving_session_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($session->status === 'completed') {
                throw new DomainException('Cannot remove scan: this receiving session is already complete.');
            }

            if ($session->receiving_events_generated_at !== null) {
                throw new DomainException('Cannot remove scan: receiving EPCIS events were already generated for this session.');
            }

            if (! in_array($session->status, ['open', 'in_progress'], true)) {
                throw new DomainException("Cannot remove scan: session status [{$session->status}] is not editable.");
            }

            if ($line->status === 'unexpected') {
                $line->delete();

                return $this->refreshSessionStatus($session);
            }

            if ($line->line_role === 'child' && $line->status === 'confirmed') {
                if ($line->parent_epc_id !== null) {
                    $parentConfirmed = ReceivingScanLine::query()
                        ->where('receiving_session_id', $session->getKey())
                        ->where('epc_id', $line->parent_epc_id)
                        ->where('status', 'confirmed')
                        ->exists();

                    if ($parentConfirmed && ! $allowChildUnderConfirmedParent) {
                        throw new DomainException('Cannot remove scan: unconfirm the parent pallet first.');
                    }
                }

                $epcId = (int) $line->epc_id;
                $deleteLine = $session->isScanFirst() && $line->parent_epc_id === null;

                if ($deleteLine) {
                    $line->delete();

                    $session->forceFill([
                        'confirmed_child_count' => max(0, (int) $session->confirmed_child_count - 1),
                        'expected_child_count' => max(0, (int) $session->expected_child_count - 1),
                        'completed_at' => null,
                    ])->save();
                } else {
                    $line->forceFill([
                        'status' => 'expected',
                        'scan_raw' => null,
                        'confirmed_at' => null,
                        'confirmed_by' => null,
                        'ilmd_mismatch_json' => null,
                    ])->save();

                    $session->forceFill([
                        'confirmed_child_count' => max(0, (int) $session->confirmed_child_count - 1),
                        'completed_at' => null,
                    ])->save();
                }

                $this->revertShipmentExpectedLines($session, [$epcId]);

                if ($session->isTransferReceive() && $session->transferring_session_id !== null) {
                    $epc = Epc::query()->find($epcId);
                    $transfer = TransferringSession::query()->find($session->transferring_session_id);

                    if ($epc !== null && $transfer !== null) {
                        $this->compensateTransferReceiveLine->handle($transfer, $epc);
                    }
                }

                return $this->refreshSessionStatus($session->refresh());
            }

            if ($line->line_role === 'parent' && $line->status === 'confirmed') {
                $parentEpcId = (int) $line->epc_id;
                $outerParentId = $line->parent_epc_id !== null
                    ? (int) $line->parent_epc_id
                    : null;

                $confirmedChildrenRemoved = ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->getKey())
                    ->where('line_role', 'child')
                    ->where('parent_epc_id', $parentEpcId)
                    ->where('status', 'confirmed')
                    ->count();

                $childEpcIds = ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->getKey())
                    ->where('line_role', 'child')
                    ->where('parent_epc_id', $parentEpcId)
                    ->pluck('epc_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->getKey())
                    ->where('line_role', 'child')
                    ->where('parent_epc_id', $parentEpcId)
                    ->delete();

                if ($session->isScanFirst()) {
                    $line->delete();

                    $session->forceFill([
                        'expected_parent_count' => max(0, (int) $session->expected_parent_count - 1),
                        'confirmed_parent_count' => max(0, (int) $session->confirmed_parent_count - 1),
                        'confirmed_child_count' => max(0, (int) $session->confirmed_child_count - $confirmedChildrenRemoved),
                        'completed_at' => null,
                    ])->save();
                } else {
                    $line->forceFill([
                        'status' => 'expected',
                        'scan_raw' => null,
                        'confirmed_at' => null,
                        'confirmed_by' => null,
                        'ilmd_mismatch_json' => null,
                    ])->save();

                    $expectedChildCount = ReceivingScanLine::query()
                        ->where('receiving_session_id', $session->getKey())
                        ->where('line_role', 'child')
                        ->count();

                    $session->forceFill([
                        'confirmed_parent_count' => max(0, (int) $session->confirmed_parent_count - 1),
                        'confirmed_child_count' => max(0, (int) $session->confirmed_child_count - $confirmedChildrenRemoved),
                        'expected_child_count' => $expectedChildCount,
                        'completed_at' => null,
                    ])->save();
                }

                $this->revertShipmentExpectedLines($session, array_values(array_unique([
                    $parentEpcId,
                    ...$childEpcIds,
                ])));

                if ($outerParentId !== null) {
                    $this->uncoverOuterPalletIfIncomplete($session->refresh(), $outerParentId);
                }

                if ($session->isTransferReceive() && $session->transferring_session_id !== null) {
                    $epc = $line->epc ?? Epc::query()->find($parentEpcId);
                    $transfer = TransferringSession::query()->find($session->transferring_session_id);

                    if ($epc !== null && $transfer !== null) {
                        $this->compensateTransferReceiveLine->handle($transfer, $epc);
                    }
                }

                return $this->refreshSessionStatus($session->refresh());
            }

            throw new DomainException('Cannot remove scan: only confirmed pallets/cases/units or unexpected lines can be removed.');
        });

        Log::info('receiving.session.scan_line_unconfirmed', [
            'receiving_session_id' => $result->getKey(),
            'receiving_scan_line_id' => $lineId,
            'actor_id' => $actorId,
        ]);

        return $result;
    }

    private function refreshSessionStatus(ReceivingSession $session): ReceivingSession
    {
        $session = $session->refresh();

        $status = ((int) $session->confirmed_parent_count > 0 || (int) $session->confirmed_child_count > 0)
            ? 'in_progress'
            : 'open';

        if ($session->status !== $status || $session->completed_at !== null) {
            $session->forceFill([
                'status' => $status,
                'completed_at' => null,
            ])->save();
        }

        return $session->refresh();
    }

    /**
     * Undo within an open session must put expected-order lines back to expected
     * so re-scan confirms again (and Day-2 already_received stays accurate).
     *
     * @param  list<int>  $epcIds
     */
    private function revertShipmentExpectedLines(ReceivingSession $session, array $epcIds): void
    {
        if ($epcIds === []
            || ! Schema::hasTable('inbound_expected_lines')
            || ! Schema::hasColumn('receiving_sessions', 'inbound_shipment_id')) {
            return;
        }

        // Confirm clears the live claim; undo must reclaim for this session so a parallel
        // opener cannot take the serial while this session still holds the expected line.
        // Scan-first stamps a unique open ASN without linking inbound_shipment_id —
        // revert by confirmed_receiving_session_id so undo still reopens those lines.
        $updates = [
            'status' => 'expected',
            'confirmed_at' => null,
            'confirmed_by' => null,
            'confirmed_receiving_session_id' => null,
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('inbound_expected_lines', 'claimed_receiving_session_id')) {
            $updates['claimed_receiving_session_id'] = $session->inbound_shipment_id !== null
                ? $session->getKey()
                : null;
        }

        $query = InboundExpectedLine::query()
            ->whereIn('epc_id', $epcIds)
            ->where('status', 'confirmed')
            ->where('confirmed_receiving_session_id', $session->getKey());

        if ($session->inbound_shipment_id !== null) {
            $query->where('inbound_shipment_id', $session->inbound_shipment_id);
        }

        $shipmentIds = (clone $query)->distinct()->pluck('inbound_shipment_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();

        $query->update($updates);

        foreach ($shipmentIds as $shipmentId) {
            InboundShipment::query()->find($shipmentId)?->refreshRollups();
        }
    }

    /**
     * Case-only SOP auto-covers the outer pallet when all nested cases are confirmed.
     * Unconfirming a nested case must uncover that pallet when siblings are no longer complete.
     */
    private function uncoverOuterPalletIfIncomplete(ReceivingSession $session, int $outerParentId): void
    {
        if (! ReceivingPolicy::forTenant(tenant())->operatorScansCaseOnly()) {
            return;
        }

        $outerParentLine = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('epc_id', $outerParentId)
            ->where('line_role', 'parent')
            ->where('status', 'confirmed')
            ->lockForUpdate()
            ->first();

        if ($outerParentLine === null) {
            return;
        }

        // Operator-scanned pallet (has scan_raw) is not an auto-cover — leave it alone.
        if (filled($outerParentLine->scan_raw)) {
            return;
        }

        $outerParent = Epc::query()->find($outerParentId);
        $siblingCaseIds = $outerParent instanceof Epc
            ? app(ResolveInboundAggregationChildEpcs::class)->childEpcIdsForParent($session, $outerParent)
            : [];

        $casePackSiblingIds = [];
        foreach ($siblingCaseIds as $childId) {
            $child = Epc::query()->find($childId);
            if ($child !== null && ReceivingPackShape::isCasePack($child, $session)) {
                $casePackSiblingIds[] = $childId;
            }
        }

        if ($casePackSiblingIds === []) {
            return;
        }

        $confirmedSiblingCount = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->whereIn('epc_id', $casePackSiblingIds)
            ->where('line_role', 'parent')
            ->where('status', 'confirmed')
            ->count();

        if ($confirmedSiblingCount >= count($casePackSiblingIds)) {
            return;
        }

        $outerParentLine->forceFill([
            'status' => 'expected',
            'confirmed_at' => null,
            'confirmed_by' => null,
            'scan_raw' => null,
            'ilmd_mismatch_json' => null,
        ])->save();

        $session->forceFill([
            'confirmed_parent_count' => max(0, (int) $session->confirmed_parent_count - 1),
            'completed_at' => null,
        ])->save();

        $this->revertShipmentExpectedLines($session, [$outerParentId]);

        Log::info('receiving.case_only.outer_pallet_uncovered', [
            'receiving_session_id' => (int) $session->getKey(),
            'outer_epc_id' => $outerParentId,
            'confirmed_case_sibling_count' => $confirmedSiblingCount,
            'case_sibling_count' => count($casePackSiblingIds),
        ]);
    }

    private function assertCanAccessSessionSite(User $user, ReceivingSession $session): void
    {
        if ($session->site_id === null) {
            if (! $user->can(Permissions::SitesAccessAll)) {
                throw new AuthorizationException('You do not have access to this receiving session.');
            }

            return;
        }

        SiteAccess::assertCanAccessSite($user, (int) $session->site_id);
    }
}
