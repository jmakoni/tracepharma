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
     * Stable hash of security-sensitive endpoint fields. Used by tenant edit
     * pages to detect when an Approved connection must be re-pended for review.
     */
    public function securityFingerprint(InboundConnection|OutboundConnection $connection): string
    {
        $settings = is_array($connection->settings) ? $connection->settings : [];
        $credentials = $this->safeCredentials($connection);

        $parts = $connection instanceof OutboundConnection
            ? $this->outboundSecurityFingerprintParts($connection, $settings, $credentials)
            : $this->inboundSecurityFingerprintParts($connection, $settings, $credentials);

        return hash('sha256', implode("\n", $parts));
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
            $fallback = $connection->tradingPartner?->name;
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
        $settings = is_array($connection->settings) ? $connection->settings : [];
        $credentials = $this->safeCredentials($connection);

        if ($connection instanceof OutboundConnection) {
            $url = $connection->effectiveEndpointUrl() ?? $connection->effectiveAs2Url();

            if (is_string($url) && trim($url) !== '') {
                $host = parse_url(trim($url), PHP_URL_HOST);

                return is_string($host) && $host !== '' ? $host : null;
            }

            // SFTP-only outbound: surface the SSH host on the central request snapshot.
            return $this->sftpHost($credentials, $settings);
        }

        if ($connection->transport === InboundTransport::Sftp) {
            return $this->sftpHost($credentials, $settings);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function safeCredentials(InboundConnection|OutboundConnection $connection): array
    {
        try {
            $credentials = $connection->credentials;
        } catch (Throwable) {
            // Legacy rows may fail decrypt under a rotated APP_KEY; fall back to settings-only host.
            return [];
        }

        return is_array($credentials) ? $credentials : [];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $credentials
     * @return list<string>
     */
    private function outboundSecurityFingerprintParts(
        OutboundConnection $connection,
        array $settings,
        array $credentials,
    ): array {
        return [
            $this->normalizeFingerprintValue($connection->effectiveEndpointUrl() ?? ($settings['endpoint_url'] ?? null)),
            $this->normalizeFingerprintValue($connection->effectiveAs2Url() ?? ($settings['as2_url'] ?? null)),
            $this->normalizeFingerprintValue($connection->effectiveAs2To() ?? ($settings['as2_to'] ?? null)),
            $this->normalizeFingerprintValue($settings['as2_from'] ?? null),
            $this->normalizeFingerprintValue($this->sftpHost($credentials, $settings)),
            $this->normalizeFingerprintValue($this->endpointHost($connection)),
            $this->normalizeFingerprintValue($this->sftpHostFingerprint($credentials, $settings)),
            $this->normalizeFingerprintValue($credentials['username'] ?? null),
            $this->normalizeFingerprintValue($settings['outbound_path'] ?? null),
            $this->normalizeFingerprintValue($settings['root'] ?? null),
            $this->hashedCredential($credentials, 'webhook_token'),
            $this->certificateFingerprint($credentials['signing_cert_pem'] ?? null),
            $this->certificateFingerprint($credentials['partner_encrypt_cert_pem'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $credentials
     * @return list<string>
     */
    private function inboundSecurityFingerprintParts(
        InboundConnection $connection,
        array $settings,
        array $credentials,
    ): array {
        return [
            $this->normalizeFingerprintValue($connection->transport?->value),
            $this->normalizeFingerprintValue($connection->trading_partner_id !== null
                ? (string) $connection->trading_partner_id
                : null),
            $this->inboundSenderGlnMappings($connection),
            $this->hashedCredential($credentials, 'webhook_token'),
            $this->hashedCredential($credentials, 'webhook_secret'),
            $this->hashedCredential($credentials, 'token'),
            $this->hashedCredential($credentials, 'as2_mdn_webhook_secret'),
            $this->normalizeFingerprintValue($this->sftpHost($credentials, $settings)),
            $this->normalizeFingerprintValue($settings['port'] ?? $credentials['port'] ?? null),
            $this->normalizeFingerprintValue($credentials['username'] ?? null),
            $this->normalizeFingerprintValue($settings['inbound_path'] ?? null),
            $this->normalizeFingerprintValue($settings['root'] ?? null),
            $this->normalizeFingerprintValue($this->sftpHostFingerprint($credentials, $settings)),
        ];
    }

    private function inboundSenderGlnMappings(InboundConnection $connection): string
    {
        $partners = $connection->relationLoaded('tradingPartners')
            ? $connection->tradingPartners
            : $connection->tradingPartners()->get();

        $entries = $partners
            ->map(function ($partner): string {
                $id = (int) $partner->getKey();
                $gln = trim((string) ($partner->pivot->sender_gln ?? ''));

                return $id.':'.$gln;
            })
            ->all();

        sort($entries, SORT_STRING);

        return implode(',', $entries);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function hashedCredential(array $credentials, string $key): string
    {
        $value = $credentials[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        return hash('sha256', trim($value));
    }

    private function certificateFingerprint(mixed $pem): string
    {
        if (! is_string($pem)) {
            return '';
        }

        $trimmed = trim($pem);

        if ($trimmed === '') {
            return '';
        }

        $fingerprint = @openssl_x509_fingerprint($trimmed, 'sha256');

        if (is_string($fingerprint) && $fingerprint !== '') {
            return $fingerprint;
        }

        return hash('sha256', $trimmed);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $settings
     */
    private function sftpHost(array $credentials, array $settings): ?string
    {
        $host = $credentials['host'] ?? $settings['host'] ?? null;

        return is_string($host) && trim($host) !== '' ? trim($host) : null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $settings
     */
    private function sftpHostFingerprint(array $credentials, array $settings): ?string
    {
        $fingerprint = $settings['host_fingerprint']
            ?? $settings['hostFingerprint']
            ?? $credentials['host_fingerprint']
            ?? $credentials['hostFingerprint']
            ?? null;

        return is_string($fingerprint) && trim($fingerprint) !== '' ? trim($fingerprint) : null;
    }

    private function normalizeFingerprintValue(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return '';
        }

        return trim($value);
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
