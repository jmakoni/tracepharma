<?php

namespace App\Actions\Receiving;

use App\Models\Receiving\ReceivingSession;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Hard-delete an open or in-progress receive without authored receiving EPCIS.
 */
final class DeleteReceivingSession
{
    public function __construct(
        private readonly RevertTransferReceiveReceivingMarks $revertTransferReceiveReceivingMarks,
    ) {}

    public function handle(ReceivingSession $session, ?int $actorId = null): void
    {
        if (! JobRoleAccess::allows(Permissions::NavReceive)) {
            throw new DomainException('Receiving is not authorized for your job role.');
        }

        $user = auth()->user();
        if ($user instanceof User) {
            $this->assertCanAccessSessionSite($user, $session);
        }

        DB::transaction(function () use ($session): void {
            $session = ReceivingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->receiving_events_generated_at !== null || $session->receiving_epcis_document_id !== null) {
                throw new DomainException('Cannot delete a receive that already has authored receiving EPCIS.');
            }

            if (! in_array($session->status, ['open', 'in_progress'], true)) {
                throw new DomainException("Cannot delete receive with status [{$session->status}].");
            }

            if ($session->isTransferReceive()) {
                if ($session->transferring_session_id !== null) {
                    $receiveGeneratedAt = TransferringSession::query()
                        ->whereKey($session->transferring_session_id)
                        ->value('receive_events_generated_at');

                    if ($receiveGeneratedAt !== null) {
                        throw new DomainException('Cannot delete a transfer receive that already has authored transfer receive EPCIS.');
                    }
                }

                $this->revertTransferReceiveReceivingMarks->handle($session);
            }

            if ($session->active_parent_epc_id !== null || $session->short_closed_parent_epc_ids !== null) {
                $session->forceFill([
                    'active_parent_epc_id' => null,
                    'short_closed_parent_epc_ids' => null,
                ])->save();
            }

            $disk = $session->invoice_disk;
            $path = $session->invoice_path;
            if (filled($disk) && filled($path)) {
                Storage::disk((string) $disk)->delete((string) $path);
            }

            $session->delete();
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
