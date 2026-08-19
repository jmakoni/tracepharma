<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\InboundTransport;
use App\Models\EpcisHubRoute;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use RuntimeException;

class RegisterEpcisHubRoute
{
    public function __construct(
        private readonly EpcisHubPlatformConfig $platformConfig,
    ) {}

    public function register(InboundConnection $connection): EpcisHubRoute
    {
        if ($connection->transport !== InboundTransport::Https) {
            throw new RuntimeException('Hub routing requires an HTTPS inbound connection.');
        }

        if (! $connection->serialization_provider->supportsHubRouting()) {
            throw new RuntimeException('This serialization provider does not support centralized hub routing.');
        }

        $tenantId = tenant()?->getKey();

        if ($tenantId === null) {
            throw new RuntimeException('Hub registration requires an initialized tenant context.');
        }

        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($tenantId);
        $gln = $tenant->gln;

        if (! is_string($gln) || ! preg_match('/^\d{13}$/', $gln)) {
            throw new RuntimeException('Tenant GLN must be set to a 13-digit value before hub registration.');
        }

        $provider = $connection->serialization_provider->hubProviderSlug();
        $this->assertTenantMayRegister($tenant, $provider);

        // default_inbound_connection_id is a preferred tie-break after sender GLN match
        // (see EpcisHubRouter). It must never skip unknown-sender fail-closed routing.
        return EpcisHubRoute::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'provider' => $provider,
                'gln' => $gln,
            ],
            [
                'default_inbound_connection_id' => $connection->getKey(),
                'is_active' => $connection->is_active,
            ],
        );
    }

    public function unregister(InboundConnection $connection): void
    {
        if (! $connection->serialization_provider->supportsHubRouting()) {
            return;
        }

        $tenantId = tenant()?->getKey();

        if ($tenantId === null) {
            return;
        }

        EpcisHubRoute::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', $connection->serialization_provider->hubProviderSlug())
            ->where('default_inbound_connection_id', $connection->getKey())
            ->delete();
    }

    private function assertTenantMayRegister(Tenant $tenant, string $provider): void
    {
        $environment = $tenant->inbound_environment;

        if (! is_string($environment) || ! in_array($environment, EpcisHubPlatformConfig::ENVIRONMENTS, true)) {
            throw new RuntimeException('Tenant inbound environment must be set to demo, stage, or prod before hub registration.');
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
