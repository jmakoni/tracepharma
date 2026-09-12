<?php

namespace App\Models;

use App\Enums\ConnectionApprovalStatus;
use App\Enums\OutboundConformanceState;
use App\Enums\OutboundConnectionKind;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Models\Epcis\EpcisDocument;
use App\Models\Shipping\OutboundShippingSession;
use App\Support\EpcisHub\PlatformOutboundEgress;
use App\Support\Integrations\OutboundConnectionKindValidator;
use App\Support\Integrations\OutboundSendPreset;
use App\Support\Integrations\OutboundTransportAvailability;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class OutboundConnection extends Model
{
    use LogsActivity;

    public const SYSTEM_KEY_EMAIL_ATTACHMENT = 'email_attachment';

    public const SYSTEM_KEY_CLIENT_PORTAL = 'client_portal';

    /**
     * When true, saving may change conformance_state (promote / break-glass actions).
     */
    public bool $allowConformanceTransition = false;

    protected $fillable = [
        'name',
        'serialization_provider',
        'transport',
        'trading_partner_id',
        'network_profile_id',
        'override_endpoint',
        'is_active',
        'approval_status',
        'approval_note',
        'is_default',
        'conformance_state',
        'credentials',
        'settings',
        'credentials_expire_at',
        'last_sent_at',
        'last_error',
        'last_success_at',
        'last_failure_at',
        'consecutive_failures',
    ];

    /**
     * In-memory default mirrors the DB default so freshly created connections
     * read as approved (grandfathered) without a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'approval_status' => 'approved',
    ];

    protected function casts(): array
    {
        return [
            'serialization_provider' => SerializationProvider::class,
            'transport' => OutboundTransport::class,
            'conformance_state' => OutboundConformanceState::class,
            'approval_status' => ConnectionApprovalStatus::class,
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'is_system' => 'boolean',
            'override_endpoint' => 'boolean',
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'credentials_expire_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['credentials', 'last_error'])
            ->logOnlyDirty();
    }

    protected static function booted(): void
    {
        static::creating(function (OutboundConnection $connection): void {
            $connection->conformance_state = OutboundConformanceState::Test;
        });

        static::saving(function (OutboundConnection $connection): void {
            OutboundTransportAvailability::assertSavable($connection);

            // AS2 certs expire by nature: mirror the earliest cert expiry onto the
            // connection so badges/reports do not have to re-parse PEM blobs.
            if ($connection->transport === OutboundTransport::As2) {
                $connection->credentials_expire_at = $connection->as2CertExpiresAt();
            }

            if (! $connection->exists) {
                $connection->conformance_state = OutboundConformanceState::Test;

                return;
            }

            if ($connection->isDirty('conformance_state') && ! $connection->allowConformanceTransition) {
                throw new InvalidArgumentException(
                    'Outbound connection conformance state may only change via promote or break-glass actions.',
                );
            }
        });

        // Tests and legacy creates may set trading_partner_id without the pivot.
        static::created(function (OutboundConnection $connection): void {
            if ($connection->trading_partner_id === null) {
                return;
            }

            if ($connection->tradingPartners()->exists()) {
                return;
            }

            $connection->tradingPartners()->sync([(int) $connection->trading_partner_id]);
        });
    }

    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class);
    }

    public function tradingPartners(): BelongsToMany
    {
        return $this->belongsToMany(TradingPartner::class, 'outbound_connection_trading_partner')
            ->using(OutboundConnectionTradingPartner::class)
            ->withTimestamps()
            ->orderBy('trading_partners.name');
    }

    /**
     * Denormalize trading_partner_id from the pivot: sole partner when exactly one,
     * otherwise null (global or multi-partner).
     */
    public function syncTradingPartnerIdFromPartners(): void
    {
        $ids = $this->tradingPartners()
            ->orderBy('trading_partners.id')
            ->pluck('trading_partners.id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $primary = count($ids) === 1 ? $ids[0] : null;

        if ($this->trading_partner_id === null && $primary === null) {
            return;
        }

        if ($this->trading_partner_id !== null && $primary !== null && (int) $this->trading_partner_id === $primary) {
            return;
        }

        $this->forceFill(['trading_partner_id' => $primary])->saveQuietly();
    }

    /**
     * @param  list<int|string>  $partnerIds
     */
    public function syncPartners(array $partnerIds): void
    {
        $ids = collect($partnerIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $this->tradingPartners()->sync($ids);
        $this->syncTradingPartnerIdFromPartners();
    }

    public function isGlobalPartnerScope(): bool
    {
        if ($this->relationLoaded('tradingPartners')) {
            return $this->tradingPartners->isEmpty();
        }

        return ! $this->tradingPartners()->exists();
    }

    public function connectionKind(): OutboundConnectionKind
    {
        $settings = is_array($this->settings) ? $this->settings : [];
        $stored = $settings['kind'] ?? null;

        if (is_string($stored) && $stored !== '') {
            $kind = OutboundConnectionKind::tryFrom($stored);
            if ($kind !== null) {
                return $kind;
            }
        }

        $provider = $this->serialization_provider instanceof SerializationProvider
            ? $this->serialization_provider
            : SerializationProvider::Other;
        $transport = $this->transport instanceof OutboundTransport
            ? $this->transport
            : OutboundTransport::Https;
        $partnerCount = $this->relationLoaded('tradingPartners')
            ? $this->tradingPartners->count()
            : $this->tradingPartners()->count();

        return OutboundSendPreset::inferKind(
            $provider,
            $transport,
            $partnerCount,
            $this->isSystemTemplate(),
        );
    }

    public function persistKind(OutboundConnectionKind $kind, ?string $profileKey = null): void
    {
        $settings = is_array($this->settings) ? $this->settings : [];
        $settings['kind'] = $kind->value;
        if ($profileKey !== null && $profileKey !== '') {
            $settings['profile_key'] = $profileKey;
        }

        $this->forceFill(['settings' => $settings])->saveQuietly();
    }

    /**
     * Validate kind/transport/partners and dual-hub membership after pivot sync.
     *
     * @param  list<int>  $partnerIds
     */
    public function assertAssignableConfiguration(?OutboundConnectionKind $kind = null, ?array $partnerIds = null): void
    {
        if ($this->isSystemTemplate()) {
            return;
        }

        $kind ??= $this->connectionKind();
        $provider = $this->serialization_provider instanceof SerializationProvider
            ? $this->serialization_provider
            : SerializationProvider::Other;
        $transport = $this->transport instanceof OutboundTransport
            ? $this->transport
            : OutboundTransport::Https;
        $partnerIds ??= $this->tradingPartners()
            ->pluck('trading_partners.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        OutboundConnectionKindValidator::assertSavable(
            $kind,
            $provider,
            $transport,
            $partnerIds,
            $this->isSystemTemplate(),
        );

        if (in_array($transport, [OutboundTransport::Https, OutboundTransport::Sftp, OutboundTransport::As2], true)
            && $partnerIds !== []
        ) {
            OutboundConnectionKindValidator::assertNoAmbiguousHubMembership(
                $this,
                $partnerIds,
                $this->conformanceState(),
            );
        }

        if ($this->usesNetworkProfileEndpoint() && $this->networkProfile() === null) {
            throw new DomainException('Linked network profile was not found. Pick a network profile or use your own endpoint.');
        }
    }

    public function usesPlatformOutboundHub(): bool
    {
        return app(PlatformOutboundEgress::class)->usesPlatformEgress($this);
    }

    public function networkProfile(): ?OutboundNetworkProfile
    {
        $id = $this->network_profile_id;

        if (! is_numeric($id) || (int) $id <= 0) {
            return null;
        }

        return OutboundNetworkProfile::query()->find((int) $id);
    }

    public function usesNetworkProfileEndpoint(): bool
    {
        return $this->network_profile_id !== null && ! $this->override_endpoint;
    }

    public function effectiveEndpointUrl(): ?string
    {
        if ($this->usesNetworkProfileEndpoint()) {
            $url = $this->networkProfile()?->endpoint_url;

            if (is_string($url) && trim($url) !== '') {
                return trim($url);
            }
        }

        $settings = is_array($this->settings) ? $this->settings : [];
        $endpoint = $settings['endpoint_url'] ?? $settings['webhook_url'] ?? null;

        return is_string($endpoint) && $endpoint !== '' ? $endpoint : null;
    }

    public function effectiveAs2To(): ?string
    {
        if ($this->usesNetworkProfileEndpoint()) {
            $to = $this->networkProfile()?->as2_to;

            if (is_string($to) && trim($to) !== '') {
                return trim($to);
            }
        }

        $settings = is_array($this->settings) ? $this->settings : [];
        $to = $settings['as2_to'] ?? null;

        return is_string($to) && $to !== '' ? $to : null;
    }

    public function effectiveAs2Url(): ?string
    {
        if ($this->usesNetworkProfileEndpoint()) {
            $url = $this->networkProfile()?->as2_url;

            if (is_string($url) && trim($url) !== '') {
                return trim($url);
            }
        }

        $settings = is_array($this->settings) ? $this->settings : [];
        $url = $settings['as2_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function effectiveAs2Subject(): ?string
    {
        if ($this->usesNetworkProfileEndpoint()) {
            $subject = $this->networkProfile()?->as2_subject;

            if (is_string($subject) && trim($subject) !== '') {
                return trim($subject);
            }
        }

        $settings = is_array($this->settings) ? $this->settings : [];
        $subject = $settings['as2_subject'] ?? null;

        return is_string($subject) && $subject !== '' ? $subject : null;
    }

    public function shippingSessions(): HasMany
    {
        return $this->hasMany(OutboundShippingSession::class);
    }

    public function openShippingSessionCount(): int
    {
        return $this->shippingSessions()
            ->whereIn('status', ['open', 'in_progress'])
            ->count();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EpcisDocument::class);
    }

    /**
     * @return list<string>
     */
    public static function as2CertificateCredentialKeys(): array
    {
        return [
            'signing_cert_pem',
            'signing_key_pem',
            'partner_encrypt_cert_pem',
        ];
    }

    public function as2CertificatesConfigured(): bool
    {
        if ($this->transport !== OutboundTransport::As2) {
            return false;
        }

        $credentials = $this->credentials ?? [];

        foreach (self::as2CertificateCredentialKeys() as $key) {
            if (filled(Arr::get($credentials, $key))) {
                return true;
            }
        }

        return false;
    }

    public function as2SmimeSigningImplemented(): bool
    {
        return true;
    }

    public function as2SmimeActive(): bool
    {
        if ($this->transport !== OutboundTransport::As2) {
            return false;
        }

        $credentials = $this->credentials ?? [];
        $canSign = filled(Arr::get($credentials, 'signing_cert_pem')) && filled(Arr::get($credentials, 'signing_key_pem'));
        $canEncrypt = filled(Arr::get($credentials, 'partner_encrypt_cert_pem'));

        return $canSign || $canEncrypt;
    }

    /**
     * Earliest validTo among AS2 signing / partner encrypt certificates, if parseable.
     */
    public function as2CertExpiresAt(): ?Carbon
    {
        if ($this->transport !== OutboundTransport::As2) {
            return null;
        }

        $credentials = $this->credentials ?? [];
        $earliest = null;

        foreach (['signing_cert_pem', 'partner_encrypt_cert_pem'] as $key) {
            $pem = Arr::get($credentials, $key);
            if (! is_string($pem) || trim($pem) === '') {
                continue;
            }

            $parsed = @openssl_x509_parse($pem);
            if (! is_array($parsed)) {
                continue;
            }

            $validTo = $parsed['validTo_time_t'] ?? null;
            if (! is_numeric($validTo)) {
                continue;
            }

            $expires = Carbon::createFromTimestampUTC((int) $validTo);
            if ($earliest === null || $expires->lt($earliest)) {
                $earliest = $expires;
            }
        }

        return $earliest;
    }

    public function lastSuccessAt(): ?Carbon
    {
        if ($this->last_sent_at === null) {
            return null;
        }

        if (filled($this->last_error)) {
            return null;
        }

        return $this->last_sent_at;
    }

    public function certExpiryWarning(): bool
    {
        $expires = $this->as2CertExpiresAt();
        if ($expires === null) {
            return false;
        }

        $days = max(1, (int) config('tracepharma.outbound.cert_warning_days', 30));

        return $expires->lte(now()->addDays($days));
    }

    public function conformanceState(): OutboundConformanceState
    {
        $state = $this->conformance_state;

        return $state instanceof OutboundConformanceState
            ? $state
            : OutboundConformanceState::Test;
    }

    public function isSystemTemplate(): bool
    {
        return (bool) $this->is_system;
    }

    public function isApproved(): bool
    {
        return $this->approval_status === ConnectionApprovalStatus::Approved;
    }

    public function isPendingApproval(): bool
    {
        return $this->approval_status === ConnectionApprovalStatus::Pending;
    }

    public function isRejected(): bool
    {
        return $this->approval_status === ConnectionApprovalStatus::Rejected;
    }

    public function isSuspended(): bool
    {
        return $this->approval_status === ConnectionApprovalStatus::Suspended;
    }
}
