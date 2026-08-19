<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Tenant;
use App\Support\TenantSettings;
use DomainException;

final class TenantKillSwitches
{
    public const OUTBOUND_EPCIS = 'outbound_epcis';

    public const INBOUND_EPCIS = 'inbound_epcis';

    public const SANCTUM_API = 'sanctum_api';

    public const WMS_WEBHOOKS = 'wms_webhooks';

    /** @var list<string> */
    public const KEYS = [
        self::OUTBOUND_EPCIS,
        self::INBOUND_EPCIS,
        self::SANCTUM_API,
        self::WMS_WEBHOOKS,
    ];

    public function __construct(
        private readonly TenantSettings $settings,
    ) {}

    public static function forTenant(?Tenant $tenant = null): self
    {
        if ($tenant === null) {
            $current = tenant();

            $tenant = $current instanceof Tenant ? $current : null;
        }

        return new self(TenantSettings::forTenant($tenant));
    }

    public function outboundEpcisKilled(): bool
    {
        return $this->settings->outboundEpcisKilled();
    }

    public function inboundEpcisKilled(): bool
    {
        return $this->settings->inboundEpcisKilled();
    }

    public function sanctumApiKilled(): bool
    {
        return $this->settings->sanctumApiKilled();
    }

    public function wmsWebhooksKilled(): bool
    {
        return $this->settings->wmsWebhooksKilled();
    }

    public function isKilled(string $key): bool
    {
        self::assertKnownKey($key);

        return match ($key) {
            self::OUTBOUND_EPCIS => $this->outboundEpcisKilled(),
            self::INBOUND_EPCIS => $this->inboundEpcisKilled(),
            self::SANCTUM_API => $this->sanctumApiKilled(),
            self::WMS_WEBHOOKS => $this->wmsWebhooksKilled(),
        };
    }

    public function assertNotKilled(string $key, ?Tenant $tenant = null): void
    {
        $resolved = $tenant !== null ? self::forTenant($tenant) : $this;

        if (! $resolved->isKilled($key)) {
            return;
        }

        $message = self::blockedMessage($key);

        if (request()->expectsJson() || request()->is('api/*')) {
            abort(403, $message);
        }

        throw new DomainException($message);
    }

    public static function blockedMessage(string $key): string
    {
        self::assertKnownKey($key);

        return match ($key) {
            self::OUTBOUND_EPCIS => 'Outbound EPCIS is disabled for this organization.',
            self::INBOUND_EPCIS => 'Inbound EPCIS is disabled for this organization.',
            self::SANCTUM_API => 'API access is disabled for this organization.',
            self::WMS_WEBHOOKS => 'WMS webhooks are disabled for this organization.',
        };
    }

    private static function assertKnownKey(string $key): void
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException("Unknown tenant kill switch [{$key}].");
        }
    }
}
