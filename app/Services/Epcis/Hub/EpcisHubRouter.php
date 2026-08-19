<?php

declare(strict_types=1);

namespace App\Services\Epcis\Hub;

use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Models\EpcisHubRoute;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Epcis\SbdhHeaderExtractor;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Integrations\InboundConnectivityProbe;
use Illuminate\Support\Collection;
use RuntimeException;

class EpcisHubRouter
{
    public function __construct(
        private readonly SbdhHeaderExtractor $sbdhExtractor,
        private readonly EpcisHubPlatformConfig $platformConfig,
    ) {}

    public function resolve(string $provider, string $content, string $environment): HubRouteResolution
    {
        $provider = strtolower(trim($provider));
        $environment = strtolower(trim($environment));

        if (! in_array($provider, $this->platformConfig->enabledProviders($environment), true)) {
            throw new RuntimeException("Unsupported EPCIS hub provider [{$provider}].");
        }

        if (InboundConnectivityProbe::isProbe($content)) {
            return HubRouteResolution::probe();
        }

        $parties = $this->sbdhExtractor->extract($content);
        $receiverGln = $parties['receiver_gln'];

        if ($receiverGln === null) {
            throw new RuntimeException('SBDH receiver GLN could not be determined from the payload.');
        }

        $tenant = $this->resolveTenant($provider, $receiverGln, $environment);
        $this->assertTenantMayReceive($tenant, $provider, $environment);
        $senderGln = $parties['sender_gln'];

        $route = EpcisHubRoute::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', $provider)
            ->where('gln', $receiverGln)
            ->where('is_active', true)
            ->first();

        $preferredConnectionId = $route?->default_inbound_connection_id;

        $connection = $tenant->run(function () use ($provider, $senderGln, $preferredConnectionId): InboundConnection {
            // Sender must be resolved before any preferred-connection shortcut.
            // default_inbound_connection_id is only a tie-break among matched senders
            // (or a fallback for truly senderless payloads) — never an unknown-sender bypass.
            $matched = $this->connectionsMatchingSender($provider, $senderGln);

            if ($senderGln !== null) {
                if ($matched->isEmpty()) {
                    throw new RuntimeException("Sender GLN [{$senderGln}] is not registered to a trading partner for hub routing on this tenant.");
                }

                if ($preferredConnectionId !== null) {
                    $preferred = $matched->firstWhere('id', (int) $preferredConnectionId);
                    if ($preferred !== null) {
                        return $preferred;
                    }
                }

                return $matched->first();
            }

            if ($preferredConnectionId !== null) {
                $preferred = $this->findConnection((int) $preferredConnectionId, $provider);
                if ($preferred !== null) {
                    return $preferred;
                }
            }

            $connection = $this->defaultConnectionForProvider($provider);

            if ($connection === null) {
                throw new RuntimeException('No active inbound connection is registered for hub routing on this tenant.');
            }

            return $connection;
        });

        return HubRouteResolution::routed($tenant, $connection, $receiverGln, $senderGln);
    }

    private function assertTenantMayReceive(Tenant $tenant, string $provider, string $environment): void
    {
        if ($tenant->inbound_environment !== $environment) {
            throw new RuntimeException(
                "Tenant inbound environment does not match hub environment [{$environment}].",
            );
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
            throw new RuntimeException("Unsupported EPCIS hub provider [{$provider}].");
        }
    }

    private function resolveTenant(string $provider, string $receiverGln, string $environment): Tenant
    {
        $routeTenantIds = EpcisHubRoute::query()
            ->where('provider', $provider)
            ->where('gln', $receiverGln)
            ->where('is_active', true)
            ->pluck('tenant_id')
            ->unique()
            ->values();

        $routeTenants = $routeTenantIds->isEmpty()
            ? collect()
            : Tenant::query()
                ->whereIn('id', $routeTenantIds)
                ->where('inbound_environment', $environment)
                ->get();

        if ($routeTenants->count() === 1) {
            return $routeTenants->first();
        }

        if ($routeTenants->count() > 1) {
            throw new RuntimeException("Receiver GLN [{$receiverGln}] is registered for multiple tenants.");
        }

        $tenantMatches = Tenant::query()
            ->where('gln', $receiverGln)
            ->where('inbound_environment', $environment)
            ->get();

        if ($tenantMatches->count() === 1) {
            return $tenantMatches->first();
        }

        if ($tenantMatches->count() > 1) {
            throw new RuntimeException("Receiver GLN [{$receiverGln}] matches multiple tenant records.");
        }

        throw new RuntimeException("No tenant is registered for receiver GLN [{$receiverGln}].");
    }

    private function findConnection(int $connectionId, string $provider): ?InboundConnection
    {
        $serializationProvider = $this->serializationProviderForHub($provider);

        return InboundConnection::query()
            ->whereKey($connectionId)
            ->where('is_active', true)
            ->where('transport', InboundTransport::Https)
            ->where('serialization_provider', $serializationProvider)
            ->first();
    }

    /**
     * Active HTTPS connections that claim this SBDH sender GLN (pivot sender_gln or legacy trading_partner_id).
     *
     * @return Collection<int, InboundConnection>
     */
    private function connectionsMatchingSender(string $provider, ?string $senderGln): Collection
    {
        if ($senderGln === null) {
            return collect();
        }

        $serializationProvider = $this->serializationProviderForHub($provider);

        $pivotMatches = InboundConnection::query()
            ->where('is_active', true)
            ->where('transport', InboundTransport::Https)
            ->where('serialization_provider', $serializationProvider)
            ->whereHas('tradingPartners', fn ($query) => $query->where('inbound_connection_trading_partner.sender_gln', $senderGln))
            ->orderBy('name')
            ->get();

        if ($pivotMatches->isNotEmpty()) {
            return $pivotMatches;
        }

        $partnerIds = TradingPartner::query()
            ->where('gln', $senderGln)
            ->pluck('id');

        if ($partnerIds->isEmpty()) {
            return collect();
        }

        return InboundConnection::query()
            ->where('is_active', true)
            ->where('transport', InboundTransport::Https)
            ->where('serialization_provider', $serializationProvider)
            ->whereIn('trading_partner_id', $partnerIds)
            ->orderBy('name')
            ->get();
    }

    private function defaultConnectionForProvider(string $provider): ?InboundConnection
    {
        $serializationProvider = $this->serializationProviderForHub($provider);

        return InboundConnection::query()
            ->where('is_active', true)
            ->where('transport', InboundTransport::Https)
            ->where('serialization_provider', $serializationProvider)
            ->orderBy('name')
            ->first();
    }

    private function serializationProviderForHub(string $provider): SerializationProvider
    {
        return match ($provider) {
            'systech' => SerializationProvider::Systech,
            'unitrace' => SerializationProvider::UniTrace,
            default => throw new RuntimeException("Unsupported EPCIS hub provider [{$provider}]."),
        };
    }

    /**
     * @return Collection<int, EpcisHubRoute>
     */
    public function routesForTenant(string $tenantId): Collection
    {
        return EpcisHubRoute::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('provider')
            ->get();
    }
}
