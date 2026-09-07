<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\ConnectionApprovalStatus;
use App\Enums\InboundTransport;
use App\Models\Admin;
use App\Models\ConnectionApprovalRequest;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Notifications\ConnectionReviewRequestedNotification;
use App\Support\Auth\Permissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

class RegisterConnectionApprovalRequest
{
    /**
     * Upsert the central approval-request row for a tenant connection so platform
     * admins can review it without entering the tenant. Resets any prior review.
     */
    public function register(InboundConnection|OutboundConnection $connection): ConnectionApprovalRequest
    {
        $tenantId = tenant()?->getKey();

        if ($tenantId === null) {
            throw new RuntimeException('Connection approval requests require an initialized tenant context.');
        }

        $direction = $connection instanceof InboundConnection
            ? ConnectionApprovalRequest::DIRECTION_INBOUND
            : ConnectionApprovalRequest::DIRECTION_OUTBOUND;

        $request = ConnectionApprovalRequest::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'direction' => $direction,
                'connection_id' => (int) $connection->getKey(),
            ],
            [
                'connection_name' => $connection->name,
                'provider' => $connection->serialization_provider?->value,
                'transport' => $connection->transport?->value,
                'counterparty' => $this->counterpartySummary($connection),
                'endpoint_host' => $this->endpointHost($connection),
                'requested_by' => $this->requestedBy(),
                'status' => ConnectionApprovalStatus::Pending,
                'reviewed_by_admin_id' => null,
                'reviewed_at' => null,
                'review_note' => null,
            ],
        );

        $this->notifyReviewers($request);

        return $request;
    }

    /**
     * Alert platform reviewers (admins with tenants.manage + the support mailbox)
     * that a connection awaits review. Throttled per connection so repeated
     * tenant edits do not spam the queue.
     */
    private function notifyReviewers(ConnectionApprovalRequest $request): void
    {
        $cacheKey = 'connection_review_requested:'.$request->getKey();

        if (Cache::has($cacheKey)) {
            return;
        }

        // Admin + role tables live on the central connection; register() is always
        // invoked inside a tenant context, so step out before querying them.
        $resume = tenancy()->initialized ? tenant() : null;

        if ($resume !== null) {
            tenancy()->end();
        }

        try {
            $notification = ConnectionReviewRequestedNotification::fromRequest($request);

            $admins = Admin::permission(Permissions::TenantsManage)->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, $notification);
            }

            $supportEmail = config('tracepharma.platform_support_email');

            if (is_string($supportEmail) && $supportEmail !== '') {
                Notification::route('mail', $supportEmail)->notify($notification);
            }

            Cache::put($cacheKey, now()->toIso8601String(), now()->addHour());
        } catch (Throwable) {
            // Reviewer alerts must never block connection registration.
        } finally {
            if ($resume !== null) {
                tenancy()->initialize($resume);
            }
        }
    }

    /**
     * Backfill a central row for a connection that predates the approval gate.
     * Mirrors the connection's current status and leaves any existing central
     * row (e.g. a live pending request) untouched.
     */
    public function registerGrandfathered(InboundConnection|OutboundConnection $connection): ?ConnectionApprovalRequest
    {
        $tenantId = tenant()?->getKey();

        if ($tenantId === null) {
            throw new RuntimeException('Connection approval requests require an initialized tenant context.');
        }

        $direction = $connection instanceof InboundConnection
            ? ConnectionApprovalRequest::DIRECTION_INBOUND
            : ConnectionApprovalRequest::DIRECTION_OUTBOUND;

        $exists = ConnectionApprovalRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('direction', $direction)
            ->where('connection_id', (int) $connection->getKey())
            ->exists();

        if ($exists) {
            return null;
        }

        $status = $connection->approval_status;

        return ConnectionApprovalRequest::query()->create([
            'tenant_id' => $tenantId,
            'direction' => $direction,
            'connection_id' => (int) $connection->getKey(),
            'connection_name' => $connection->name,
            'provider' => $connection->serialization_provider?->value,
            'transport' => $connection->transport?->value,
            'counterparty' => $this->counterpartySummary($connection),
            'endpoint_host' => $this->endpointHost($connection),
            'requested_by' => null,
            'status' => $status,
            'reviewed_by_admin_id' => null,
            'reviewed_at' => null,
            'review_note' => $status === ConnectionApprovalStatus::Approved
                ? 'Grandfathered: connection existed before platform approval was introduced.'
                : null,
        ]);
    }

    private function counterpartySummary(InboundConnection|OutboundConnection $connection): ?string
    {
        $names = $connection->tradingPartners()
            ->orderBy('trading_partners.name')
            ->pluck('trading_partners.name')
            ->all();

        if ($names === [] && $connection->trading_partner_id !== null) {
            $fallback = $connection->tradingPartner()?->name;
            $names = is_string($fallback) && $fallback !== '' ? [$fallback] : [];
        }

        if ($names === []) {
            return null;
        }

        $shown = array_slice($names, 0, 3);
        $summary = implode(', ', $shown);
        $remaining = count($names) - count($shown);

        return $remaining > 0 ? "{$summary} +{$remaining} more" : $summary;
    }

    private function endpointHost(InboundConnection|OutboundConnection $connection): ?string
    {
        $url = null;

        if ($connection instanceof OutboundConnection) {
            $url = $connection->effectiveEndpointUrl() ?? $connection->effectiveAs2Url();
        } elseif ($connection->transport === InboundTransport::Sftp) {
            $host = $connection->settings['host'] ?? null;

            return is_string($host) && trim($host) !== '' ? trim($host) : null;
        }

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $host = parse_url(trim($url), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    private function requestedBy(): ?string
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $name = trim((string) ($user->name ?? ''));
        $email = trim((string) ($user->email ?? ''));

        if ($name !== '' && $email !== '') {
            return "{$name} ({$email})";
        }

        return $name !== '' ? $name : ($email !== '' ? $email : null);
    }
}
