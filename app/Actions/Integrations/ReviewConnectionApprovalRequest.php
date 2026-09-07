<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\ConnectionApprovalStatus;
use App\Enums\TenantRole;
use App\Models\Admin;
use App\Models\ConnectionApprovalRequest;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Models\User;
use App\Notifications\ConnectionReviewedNotification;
use App\Support\Admin\PlatformAudit;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Throwable;

class ReviewConnectionApprovalRequest
{
    public function approve(ConnectionApprovalRequest $request, Admin $admin): void
    {
        $this->review($request, $admin, ConnectionApprovalStatus::Approved, null);
    }

    public function reject(ConnectionApprovalRequest $request, Admin $admin, string $note): void
    {
        $note = trim($note);

        if ($note === '') {
            throw new RuntimeException('A rejection note is required so the tenant knows what to fix.');
        }

        $this->review($request, $admin, ConnectionApprovalStatus::Rejected, $note);
    }

    /**
     * Platform revocation of a previously approved connection: traffic stops at
     * every enforcement point (hub router, inbound webhook, SFTP poll, outbound
     * transmitter) until the connection is resumed.
     */
    public function suspend(ConnectionApprovalRequest $request, Admin $admin, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('A suspension reason is required so the tenant knows what to fix.');
        }

        if ($request->status !== ConnectionApprovalStatus::Approved) {
            throw new RuntimeException('Only approved connections can be suspended.');
        }

        $this->apply($request, $admin, ConnectionApprovalStatus::Suspended, $reason);
    }

    public function resume(ConnectionApprovalRequest $request, Admin $admin): void
    {
        if ($request->status !== ConnectionApprovalStatus::Suspended) {
            throw new RuntimeException('Only suspended connections can be resumed.');
        }

        $this->apply($request, $admin, ConnectionApprovalStatus::Approved, null);
    }

    private function review(
        ConnectionApprovalRequest $request,
        Admin $admin,
        ConnectionApprovalStatus $status,
        ?string $note,
    ): void {
        if (! $request->isPending()) {
            throw new RuntimeException('Only pending connection requests can be reviewed.');
        }

        $this->apply($request, $admin, $status, $note);
    }

    private function apply(
        ConnectionApprovalRequest $request,
        Admin $admin,
        ConnectionApprovalStatus $status,
        ?string $note,
    ): void {
        $tenant = $request->tenant;

        if ($tenant === null) {
            throw new RuntimeException('Tenant not found for this connection request.');
        }

        TenantRunner::run($tenant, function () use ($request, $status, $note, $tenant): void {
            $connection = $request->direction === ConnectionApprovalRequest::DIRECTION_INBOUND
                ? InboundConnection::query()->find($request->connection_id)
                : OutboundConnection::query()->find($request->connection_id);

            if ($connection === null) {
                throw new RuntimeException('The connection no longer exists in this tenant.');
            }

            $connection->approval_status = $status;
            $connection->approval_note = in_array($status, [
                ConnectionApprovalStatus::Rejected,
                ConnectionApprovalStatus::Suspended,
            ], true) ? $note : null;
            $connection->save();

            $this->notifyTenantOwners($request, $status, $note, (string) $tenant->getKey());
        });

        $request->forceFill([
            'status' => $status,
            'reviewed_by_admin_id' => (int) $admin->getKey(),
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        PlatformAudit::record(
            'connection_request.'.$status->value,
            tenantId: (string) $tenant->getKey(),
            targetType: ConnectionApprovalRequest::class,
            targetId: (string) $request->getKey(),
            payload: [
                'direction' => $request->direction,
                'connection_id' => $request->connection_id,
                'connection_name' => $request->connection_name,
                'note' => $note,
            ],
            actor: $admin,
        );
    }

    private function notifyTenantOwners(
        ConnectionApprovalRequest $request,
        ConnectionApprovalStatus $status,
        ?string $note,
        string $tenantId,
    ): void {
        try {
            $owners = User::role(TenantRole::Owner->value)->get();
        } catch (RoleDoesNotExist) {
            $owners = collect();
        }

        if ($owners->isEmpty()) {
            return;
        }

        try {
            Notification::send($owners, new ConnectionReviewedNotification(
                (string) $request->connection_name,
                (string) $request->direction,
                $status,
                $note,
                $tenantId,
            ));
        } catch (Throwable) {
            // Notification delivery must never block a review decision.
        }
    }
}
