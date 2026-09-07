<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Models\EpcisHubRoute;
use App\Models\Tenant;
use App\Support\Admin\PlatformAudit;
use App\Support\EpcisHub\ClaimableReceiverGlns;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\EpcisHub\HubRouteConflictGuard;
use RuntimeException;

class ClaimTenantHubReceiverGln
{
    public const VIA_ADMIN = 'admin';

    public const VIA_CONNECTION_AUTO = 'connection_auto';

    public function __construct(
        private readonly EpcisHubPlatformConfig $platformConfig,
    ) {}

    public function claim(
        Tenant $tenant,
        string $provider,
        string $gln,
        bool $active = true,
        bool $allowOrphan = false,
    ): EpcisHubRoute {
        $provider = strtolower(trim($provider));
        $normalized = ClaimableReceiverGlns::normalizeStoredGln($gln);

        if ($normalized === null) {
            throw new RuntimeException('Receiver GLN must be a 13-digit value.');
        }

        $this->assertTenantMayClaim($tenant, $provider);

        if (! $allowOrphan && ! ClaimableReceiverGlns::contains($tenant, $normalized)) {
            throw new RuntimeException(
                "GLN [{$normalized}] is not the company GLN or an org-facility site GLN for this tenant.",
            );
        }

        app(HubRouteConflictGuard::class)->assertExclusive($tenant, $provider, $normalized);

        $route = EpcisHubRoute::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'provider' => $provider,
                'gln' => $normalized,
            ],
            [
                'is_active' => $active,
                'claimed_via' => self::VIA_ADMIN,
            ],
        );

        PlatformAudit::record(
            'hub_route.claimed',
            tenantId: (string) $tenant->getKey(),
            targetType: EpcisHubRoute::class,
            targetId: (string) $route->getKey(),
            payload: ['provider' => $provider, 'gln' => $normalized, 'is_active' => $active],
        );

        return $route;
    }

    public function unclaim(Tenant $tenant, string $provider, string $gln): void
    {
        $provider = strtolower(trim($provider));
        $normalized = ClaimableReceiverGlns::normalizeStoredGln($gln);

        if ($normalized === null) {
            return;
        }

        $deleted = EpcisHubRoute::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('provider', $provider)
            ->where('gln', $normalized)
            ->where('claimed_via', self::VIA_ADMIN)
            ->delete();

        if ($deleted > 0) {
            PlatformAudit::record(
                'hub_route.unclaimed',
                tenantId: (string) $tenant->getKey(),
                targetType: EpcisHubRoute::class,
                payload: ['provider' => $provider, 'gln' => $normalized],
            );
        }
    }

    private function assertTenantMayClaim(Tenant $tenant, string $provider): void
    {
        $environment = $tenant->inbound_environment;

        if (! is_string($environment) || ! in_array($environment, EpcisHubPlatformConfig::ENVIRONMENTS, true)) {
            throw new RuntimeException('Tenant inbound environment must be set to demo, stage, or prod before claiming GLNs.');
        }

        $tenantProviders = is_array($tenant->hub_providers) ? $tenant->hub_providers : [];
        $tenantProviders = array_map(
            static fn ($p) => is_string($p) ? strtolower(trim($p)) : '',
            $tenantProviders,
        );

        if (! in_array($provider, $tenantProviders, true)) {
            throw new RuntimeException(
                "Provider [{$provider}] is not enabled for this tenant's hub routing.",
            );
        }

        if (! in_array($provider, $this->platformConfig->enabledProviders($environment), true)) {
            throw new RuntimeException(
                "Provider [{$provider}] is not enabled for hub environment [{$environment}].",
            );
        }
    }
}
