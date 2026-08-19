<?php

declare(strict_types=1);

namespace App\Services\Tracing;

use App\Models\TracingRequestNotification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Issues signed partner acknowledgment links for recall broadcast emails.
 *
 * Each notification row gets a rotatable `ack_share_uuid` (never mass assignable).
 * Links are temporary signed URLs so forwarded emails stop working after TTL.
 */
final class RecallBroadcastAckService
{
    private const DEFAULT_TTL_DAYS = 90;

    public function ensureAckLink(TracingRequestNotification $notification): TracingRequestNotification
    {
        if ($notification->ack_share_uuid === null) {
            $notification->forceFill([
                'ack_share_uuid' => (string) Str::uuid(),
            ])->save();
        }

        return $notification->refresh();
    }

    /**
     * Replace the uuid: every previously shared link stops resolving.
     */
    public function rotateAckLink(TracingRequestNotification $notification): TracingRequestNotification
    {
        if ($notification->ack_share_uuid === null) {
            throw new RuntimeException('This recall notice has no acknowledgment link to rotate.');
        }

        $notification->forceFill([
            'ack_share_uuid' => (string) Str::uuid(),
        ])->save();

        $this->logAckLinkChange($notification, 'recall_broadcast_ack_link_rotated');

        return $notification->refresh();
    }

    /**
     * Drop the uuid entirely: outstanding links stop resolving until a new notice is sent.
     */
    public function revokeAckLink(TracingRequestNotification $notification): TracingRequestNotification
    {
        if ($notification->ack_share_uuid === null) {
            return $notification;
        }

        $notification->forceFill(['ack_share_uuid' => null])->save();

        $this->logAckLinkChange($notification, 'recall_broadcast_ack_link_revoked');

        return $notification->refresh();
    }

    public function signedAckUrl(TracingRequestNotification $notification): string
    {
        $notification = $this->ensureAckLink($notification);

        return URL::temporarySignedRoute(
            'tenant.recall-broadcast-ack.show',
            now()->addDays($this->linkTtlDays()),
            ['ackShareUuid' => $notification->ack_share_uuid],
        );
    }

    public function signedAckSubmitUrl(TracingRequestNotification $notification): string
    {
        $notification = $this->ensureAckLink($notification);

        return URL::temporarySignedRoute(
            'tenant.recall-broadcast-ack.acknowledge',
            now()->addDays($this->linkTtlDays()),
            ['ackShareUuid' => $notification->ack_share_uuid],
        );
    }

    public function linkTtlDays(): int
    {
        return max(1, (int) config(
            'tracepharma.recall_broadcast_ack.link_ttl_days',
            self::DEFAULT_TTL_DAYS,
        ));
    }

    /**
     * The uuid is never mass assignable, so rotate/revoke events are recorded explicitly.
     */
    private function logAckLinkChange(TracingRequestNotification $notification, string $description): void
    {
        if (! function_exists('activity')) {
            return;
        }

        activity()
            ->performedOn($notification)
            ->withProperties(array_filter([
                'tracing_request_id' => $notification->tracing_request_id,
                'trading_partner_id' => $notification->trading_partner_id,
                'user_id' => auth()->id(),
            ], static fn ($value) => $value !== null))
            ->log($description);
    }
}
