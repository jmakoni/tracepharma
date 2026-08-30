<?php

namespace App\Models;

use App\Enums\OutboundConformanceState;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Models\Epcis\EpcisDocument;
use App\Support\Integrations\OutboundTransportAvailability;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class OutboundConnection extends Model
{
    use LogsActivity;

    /**
     * When true, saving may change conformance_state (promote / break-glass actions).
     */
    public bool $allowConformanceTransition = false;

    protected $fillable = [
        'name',
        'serialization_provider',
        'transport',
        'trading_partner_id',
        'is_active',
        'is_default',
        'conformance_state',
        'credentials',
        'settings',
        'last_sent_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'serialization_provider' => SerializationProvider::class,
            'transport' => OutboundTransport::class,
            'conformance_state' => OutboundConformanceState::class,
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'last_sent_at' => 'datetime',
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
    }

    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class);
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
}
