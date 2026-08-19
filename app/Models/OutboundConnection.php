<?php

namespace App\Models;

use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Support\Integrations\OutboundTransportAvailability;
use Illuminate\Support\Arr;
use App\Models\Epcis\EpcisDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class OutboundConnection extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name',
        'serialization_provider',
        'transport',
        'trading_partner_id',
        'is_active',
        'is_default',
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
            ->logExcept(['credentials'])
            ->logOnlyDirty();
    }

    protected static function booted(): void
    {
        static::saving(function (OutboundConnection $connection): void {
            OutboundTransportAvailability::assertSavable($connection);
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
}
