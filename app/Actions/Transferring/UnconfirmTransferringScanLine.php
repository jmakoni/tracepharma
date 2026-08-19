<?php

namespace App\Actions\Transferring;

use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Undo a single confirmed transfer scan on an open session.
 */
final class UnconfirmTransferringScanLine
{
    public function handle(TransferringScanLine $line, ?int $actorId = null): TransferringSession
    {
        if (! TenantFeatures::forTenant(tenant())->supportsTransferring()) {
            throw new DomainException('Transferring is not available for this tenant profile.');
        }

        if (! JobRoleAccess::allowsForActor(Permissions::NavShip, auth()->user())) {
            throw new DomainException('Shipping is not authorized for your job role.');
        }

        $session = $line->session ?? TransferringSession::query()->find($line->transferring_session_id);
        if ($session instanceof TransferringSession) {
            $user = auth()->user();
            if ($user instanceof User) {
                SiteAccess::assertCanAccessSite($user, (int) $session->from_site_id);
            }
        }

        $lineId = (int) $line->getKey();

        $result = DB::transaction(function () use ($line): TransferringSession {
            $line = TransferringScanLine::query()
                ->whereKey($line->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $session = TransferringSession::query()
                ->whereKey($line->transferring_session_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($session->transfer_events_generated_at !== null) {
                throw new DomainException('Cannot remove scan: transferring EPCIS was already generated for this session.');
            }

            if ($session->status !== 'open') {
                throw new DomainException("Cannot remove scan: session status [{$session->status}] is not editable.");
            }

            if ($line->status !== 'confirmed') {
                throw new DomainException('Cannot remove scan: only confirmed lines can be removed.');
            }

            $line->delete();

            $session->forceFill([
                'confirmed_count' => max(0, (int) $session->confirmed_count - 1),
            ])->save();

            return $session->refresh();
        });

        Log::info('transferring.session.scan_line_unconfirmed', [
            'transferring_session_id' => $result->getKey(),
            'transferring_scan_line_id' => $lineId,
            'actor_id' => $actorId,
        ]);

        return $result;
    }
}
