<?php

namespace App\Actions\Transferring;

use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Services\Custody\EpcCustodyGate;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Ship an open transferring session (mark in_transit) and author the shipping EPCIS event.
 * Destination must scan-confirm receive before the session can complete.
 *
 * Status flips to in_transit before authoring so GenerateTransferringEpcisEvents can run;
 * on authoring failure we revert to open and clear shipped_at so the operator can retry.
 * That brief window is the unavoidable custody gap until the shipping ObjectEvent exists.
 */
final class CompleteTransferringSession
{
    public function __construct(
        private readonly GenerateTransferringEpcisEvents $generateTransferringEpcisEvents,
        private readonly EpcCustodyGate $custodyGate,
    ) {}

    public function handle(TransferringSession $session, ?int $actorId = null): TransferringSession
    {
        if (! TenantFeatures::forTenant(tenant())->supportsTransferring()) {
            throw new DomainException('Transferring is not available for this tenant profile.');
        }

        if (! JobRoleAccess::allowsForActor(Permissions::NavShip, auth()->user())) {
            throw new DomainException('Shipping is not authorized for your job role.');
        }

        $user = auth()->user();
        if ($user instanceof User) {
            SiteAccess::assertCanAccessSite($user, (int) $session->from_site_id);
        }

        $ship = DB::transaction(function () use ($session): array {
            $session = TransferringSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->status === 'completed') {
                return ['session' => $session, 'should_generate' => false];
            }

            if ($session->status === 'in_transit') {
                if ($session->transfer_events_generated_at !== null) {
                    return ['session' => $session, 'should_generate' => false];
                }

                // Prior ship flipped in_transit then died mid-authoring — recheck and retry.
                $this->assertConfirmedLinesStillMovable($session);

                return ['session' => $session, 'should_generate' => true];
            }

            if ($session->status !== 'open') {
                throw new InvalidArgumentException(
                    "Cannot ship transferring session with status [{$session->status}].",
                );
            }

            $confirmedLineCount = TransferringScanLine::query()
                ->where('transferring_session_id', $session->getKey())
                ->where('status', 'confirmed')
                ->count();

            if ($confirmedLineCount < 1) {
                throw new InvalidArgumentException(
                    'Cannot ship transferring session with no confirmed scans.',
                );
            }

            // Under the session lock, and reading the lines the lock froze: a scan
            // confirmed against the pre-lock line set would otherwise ship without ever
            // having been rechecked.
            $this->assertConfirmedLinesStillMovable($session);

            $session->forceFill([
                'status' => 'in_transit',
                'shipped_at' => now(),
            ])->save();

            return ['session' => $session->refresh(), 'should_generate' => true];
        });

        /** @var TransferringSession $session */
        $session = $ship['session'];

        if ($ship['should_generate']) {
            try {
                $this->generateTransferringEpcisEvents->handle($session, $actorId);
            } catch (Throwable $e) {
                // Authoring the shipping ObjectEvent *is* the transfer for partners and
                // regulators — leave the session open so ship can be retried.
                $this->revertIncompleteShip($session);

                $session = $session->fresh() ?? $session;
                if ($session->transfer_events_generated_at !== null) {
                    throw $e instanceof DomainException
                        ? $e
                        : new DomainException($e->getMessage(), 0, $e);
                }

                throw new DomainException(
                    'Transfer not shipped — transferring EPCIS could not be authored: '.$e->getMessage(),
                    0,
                    $e,
                );
            }
        }

        return $session->refresh();
    }

    /**
     * Scans are gated one at a time, but a quarantine hold or a destroy event can
     * land between the last scan and the ship. Recheck the whole confirmed set so no
     * ineligible unit reaches the authored transfer EPCIS.
     *
     * Called inside the ship transaction with the session row locked, so the
     * confirmed lines read here are the set that will move:
     * {@see ConfirmTransferringScan} takes the same lock before inserting a line, and
     * cannot add one between this check and the status change.
     *
     * @throws InvalidArgumentException when any confirmed unit can no longer move
     */
    private function assertConfirmedLinesStillMovable(TransferringSession $session): void
    {
        $epcIds = TransferringScanLine::query()
            ->where('transferring_session_id', $session->getKey())
            ->whereIn('status', ['confirmed', 'received'])
            ->orderBy('id')
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $this->custodyGate->assertOperableFor($epcIds, 'shipping this transfer');
    }

    /**
     * Roll back in_transit when transferring EPCIS was not authored.
     * Locked so a concurrent author that just stamped transfer_events_generated_at
     * cannot be wiped by this revert.
     */
    private function revertIncompleteShip(TransferringSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $locked = TransferringSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->transfer_events_generated_at !== null) {
                return;
            }

            if ($locked->status !== 'in_transit') {
                return;
            }

            $locked->forceFill([
                'status' => 'open',
                'shipped_at' => null,
            ])->save();
        });
    }
}
