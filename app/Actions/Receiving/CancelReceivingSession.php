<?php

namespace App\Actions\Receiving;

use App\Models\Receiving\ReceivingSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Cancel an open or in-progress receive without authored receiving EPCIS.
 */
final class CancelReceivingSession
{
    public function __construct(
        private readonly RevertTransferReceiveReceivingMarks $revertTransferReceiveReceivingMarks,
    ) {}

    public function handle(ReceivingSession $session, ?int $actorId = null): ReceivingSession
    {
        if (! JobRoleAccess::allows(Permissions::NavReceive)) {
            throw new DomainException('Receiving is not authorized for your job role.');
        }

        $user = auth()->user();
        if ($user instanceof User) {
            $this->assertCanAccessSessionSite($user, $session);
        }

        return DB::transaction(function () use ($session): ReceivingSession {
            $session = ReceivingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->receiving_events_generated_at !== null || $session->receiving_epcis_document_id !== null) {
                throw new DomainException('Cannot cancel a receive that already has authored receiving EPCIS.');
            }

            if (! in_array($session->status, ['open', 'in_progress'], true)) {
                throw new DomainException("Cannot cancel receive with status [{$session->status}].");
            }

            if ($session->isTransferReceive()) {
                $this->revertTransferReceiveReceivingMarks->handle($session);
            }

            $session->forceFill([
                'status' => 'cancelled',
                'completed_at' => now(),
            ])->save();

            return $session->refresh();
        });
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
