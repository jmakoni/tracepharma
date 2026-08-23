<?php

namespace App\Actions\Receiving;

use App\Models\Epcis\Epc;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

                $confirmedChildrenRemoved = ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->getKey())
                    ->where('line_role', 'child')
                    ->where('parent_epc_id', $parentEpcId)
                    ->where('status', 'confirmed')
                    ->count();

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
