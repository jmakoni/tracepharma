<?php

namespace App\Models;

use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Models\Epcis\EpcisDocument;
use App\Support\IntegrationEndpointUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class InboundConnection extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name',
        'serialization_provider',
        'transport',
        'trading_partner_id',
        'is_active',
        'credentials',
        'settings',
        'inbound_token',
        'last_polled_at',
        'last_received_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'serialization_provider' => SerializationProvider::class,
            'transport' => InboundTransport::class,
            'is_active' => 'boolean',
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'last_polled_at' => 'datetime',
            'last_received_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    protected static function booted(): void
    {
        static::creating(function (InboundConnection $connection): void {
            if (blank($connection->inbound_token)) {
                $connection->inbound_token = (string) Str::uuid();
            }
        });
    }

    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class);
    }

    public function tradingPartners(): BelongsToMany
    {
        return $this->belongsToMany(TradingPartner::class, 'inbound_connection_trading_partner')
            ->using(InboundConnectionTradingPartner::class)
            ->withPivot(['sender_gln', 'priority', 'is_default'])
            ->withTimestamps()
            ->orderByPivot('priority')
            ->orderByPivot('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EpcisDocument::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(InboundConnectionLog::class);
    }

    public function multiPartnerRoutingEnabled(): bool
    {
        return filter_var($this->settings['multi_partner_routing'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public function webhookUrl(?string $tenantId = null): ?string
    {
        if ($this->transport !== InboundTransport::Https) {
            return null;
        }

        $tenantId ??= tenant()?->getKey();

        if ($tenantId === null) {
            return null;
        }

        return IntegrationEndpointUrl::inboundWebhook($tenantId, (int) $this->getKey());
    }

    public function as2Url(?string $tenantId = null): ?string
    {
        if ($this->transport !== InboundTransport::As2) {
            return null;
        }

        $tenantId ??= tenant()?->getKey();

        if ($tenantId === null) {
            return null;
        }

        return IntegrationEndpointUrl::inboundAs2($tenantId, (int) $this->getKey());
    }

    public function hubUrl(): ?string
    {
        if ($this->transport !== InboundTransport::Https || ! $this->serialization_provider->supportsHubRouting()) {
            return null;
        }

        $tenant = tenant();
        $environment = is_string($tenant?->inbound_environment) && $tenant->inbound_environment !== ''
            ? $tenant->inbound_environment
            : null;

        if ($environment === null) {
            return null;
        }

        return IntegrationEndpointUrl::inboundHub(
            $this->serialization_provider->hubProviderSlug(),
            $environment,
        );
    }

    public function isHubRegistered(): bool
    {
        if (! $this->serialization_provider->supportsHubRouting()) {
            return false;
        }

        $tenantId = tenant()?->getKey();

        if ($tenantId === null) {
            return false;
        }

        return EpcisHubRoute::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', $this->serialization_provider->hubProviderSlug())
            ->where('default_inbound_connection_id', $this->getKey())
            ->where('is_active', true)
            ->exists();
    }

    public function regenerateInboundToken(): void
    {
        $this->inbound_token = (string) Str::uuid();
        $this->save();
    }
}
