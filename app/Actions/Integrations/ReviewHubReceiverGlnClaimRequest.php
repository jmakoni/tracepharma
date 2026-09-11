<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\HubReceiverGlnClaimRequestStatus;
use App\Models\Admin;
use App\Models\HubReceiverGlnClaimRequest;
use App\Models\Tenant;
use App\Support\Admin\PlatformAudit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReviewHubReceiverGlnClaimRequest
{
    public function __construct(
        private readonly ClaimTenantHubReceiverGln $claimAction,
    ) {}

    public function approve(
        HubReceiverGlnClaimRequest $request,
        Admin $admin,
        ?string $note = null,
    ): void {
        $this->review(
            $request,
            $admin,
            HubReceiverGlnClaimRequestStatus::Approved,
            $note,
        );
    }

    public function reject(
        HubReceiverGlnClaimRequest $request,
        Admin $admin,
        ?string $note = null,
    ): void {
        $this->review(
            $request,
            $admin,
            HubReceiverGlnClaimRequestStatus::Rejected,
            $note,
        );
    }

    private function review(
        HubReceiverGlnClaimRequest $request,
        Admin $admin,
        HubReceiverGlnClaimRequestStatus $status,
        ?string $note,
    ): void {
        $note = $this->normalizeNote($note);
        $connection = $request->getConnectionName();

        DB::connection($connection)->transaction(function () use ($request, $admin, $status, $note): void {
            $lockedRequest = HubReceiverGlnClaimRequest::query()
                ->lockForUpdate()
                ->find($request->getKey());

            if ($lockedRequest === null) {
                throw new RuntimeException('Hub receiver GLN claim request no longer exists.');
            }

            if (! $lockedRequest->isPending()) {
                throw new RuntimeException('Only pending hub receiver GLN claim requests can be reviewed.');
            }

            $tenant = Tenant::query()->find($lockedRequest->tenant_id);

            if ($tenant === null) {
                throw new RuntimeException('Tenant not found for this hub receiver GLN claim request.');
            }

            if ($status === HubReceiverGlnClaimRequestStatus::Approved) {
                $this->claimAction->claim(
                    $tenant,
                    $lockedRequest->provider,
                    $lockedRequest->gln,
                );
            }

            $lockedRequest->forceFill([
                'status' => $status,
                'reviewed_by_admin_id' => (int) $admin->getKey(),
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            PlatformAudit::record(
                'hub_receiver_gln_claim_request.'.$status->value,
                tenantId: (string) $tenant->getKey(),
                targetType: HubReceiverGlnClaimRequest::class,
                targetId: (string) $lockedRequest->getKey(),
                payload: [
                    'provider' => $lockedRequest->provider,
                    'gln' => $lockedRequest->gln,
                    'note' => $note,
                ],
                actor: $admin,
            );

            // Task 5 will notify the requesting tenant after review is persisted.
        });
    }

    private function normalizeNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $note = trim($note);

        return $note === '' ? null : $note;
    }
}
