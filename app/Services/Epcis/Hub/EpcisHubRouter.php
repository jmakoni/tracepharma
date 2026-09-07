<?php

declare(strict_types=1);

namespace App\Services\Epcis\Hub;

use App\Enums\ConnectionApprovalStatus;
use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Models\EpcisHubRoute;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Epcis\SbdhHeaderExtractor;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Integrations\InboundConnectivityProbe;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Database\Eloquent\Builder;
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

        $connection = TenantRunner::run($tenant, function () use ($provider, $senderGln, $preferredConnectionId): InboundConnection {
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

        if ($route !== null) {
            EpcisHubRoute::query()
                ->whereKey($route->getKey())
                ->update(['last_routed_at' => now()]);
        }

        return HubRouteResolution::routed($tenant, $connection, $receiverGln, $senderGln);
    }

    /**
     * Dry-run the routing decision for a sender/receiver GLN pair without a
     * payload. Returns the ordered resolution chain with the first failing
     * step, so admins can diagnose "no route" cases in one click.
     *
     * @return list<array{step: string, ok: bool, detail: string}>
     */
    public function describeResolution(string $provider, string $receiverGln, ?string $senderGln, string $environment): array
    {
        $provider = strtolower(trim($provider));
        $environment = strtolower(trim($environment));
        $steps = [];

        $providerEnabled = in_array($provider, $this->platformConfig->enabledProviders($environment), true);
        $steps[] = [
            'step' => 'Provider enabled',
            'ok' => $providerEnabled,
            'detail' => $providerEnabled
                ? "Provider [{$provider}] is enabled for [{$environment}]."
                : "Provider [{$provider}] is not enabled for hub environment [{$environment}].",
        ];

        if (! $providerEnabled) {
            return $steps;
        }

        try {
            $tenant = $this->resolveTenant($provider, $receiverGln, $environment);
            $steps[] = [
                'step' => 'Receiver GLN → tenant',
                'ok' => true,
                'detail' => "Receiver GLN [{$receiverGln}] routes to tenant [{$tenant->name}] ({$tenant->getKey()}).",
            ];
        } catch (RuntimeException $exception) {
            $steps[] = [
                'step' => 'Receiver GLN → tenant',
                'ok' => false,
                'detail' => $exception->getMessage(),
            ];

            return $steps;
        }

        try {
            $this->assertTenantMayReceive($tenant, $provider, $environment);
            $steps[] = [
                'step' => 'Tenant entitlement',
                'ok' => true,
                'detail' => "Tenant inbound environment and hub providers allow [{$provider}].",
            ];
        } catch (RuntimeException $exception) {
            $steps[] = [
                'step' => 'Tenant entitlement',
                'ok' => false,
                'detail' => $exception->getMessage(),
            ];

            return $steps;
        }

        $route = EpcisHubRoute::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', $provider)
            ->where('gln', $receiverGln)
            ->where('is_active', true)
            ->first();

        $preferredConnectionId = $route?->default_inbound_connection_id;

        try {
            $connection = TenantRunner::run($tenant, function () use ($provider, $senderGln, $preferredConnectionId): InboundConnection {
                $matched = $this->connectionsMatchingSender($provider, $senderGln);

                if ($senderGln !== null) {
                    if ($matched->isEmpty()) {
                        throw new RuntimeException("Sender GLN [{$senderGln}] is not registered to a trading partner for hub routing on this tenant.");
                    }

                    $preferred = $preferredConnectionId !== null
                        ? $matched->firstWhere('id', (int) $preferredConnectionId)
                        : null;

                    return $preferred ?? $matched->first();
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

            $steps[] = [
                'step' => 'Sender GLN → connection',
                'ok' => true,
                'detail' => "Resolves to inbound connection [{$connection->name}] (#{$connection->getKey()}).",
            ];
        } catch (RuntimeException $exception) {
            $steps[] = [
                'step' => 'Sender GLN → connection',
                'ok' => false,
                'detail' => $exception->getMessage(),
            ];
        }

        return $steps;
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
        return $this->approvedHubBase($provider)
            ->whereKey($connectionId)
            ->first();
    }

    /**
     * Active, platform-approved HTTPS connections that claim this SBDH sender GLN
     * (pivot sender_gln or legacy trading_partner_id).
     *
     * @return Collection<int, InboundConnection>
     */
    private function connectionsMatchingSender(string $provider, ?string $senderGln): Collection
    {
        if ($senderGln === null) {
            return collect();
        }

        $pivotMatches = $this->approvedHubBase($provider)
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

        return $this->approvedHubBase($provider)
            ->whereIn('trading_partner_id', $partnerIds)
            ->orderBy('name')
            ->get();
    }

    private function defaultConnectionForProvider(string $provider): ?InboundConnection
    {
        return $this->approvedHubBase($provider)
            ->orderBy('name')
            ->first();
    }

    /**
     * Active + platform-approved HTTPS connections for the hub's serialization provider;
     * pending/rejected connections never receive hub-routed traffic.
     *
     * @return Builder<InboundConnection>
     */
    private function approvedHubBase(string $provider): Builder
    {
        return InboundConnection::query()
            ->where('is_active', true)
            ->where('approval_status', ConnectionApprovalStatus::Approved->value)
            ->where('transport', InboundTransport::Https)
            ->where('serialization_provider', $this->serializationProviderForHub($provider));
    }

    private function serializationProviderForHub(string $provider): SerializationProvider
    {
        return match ($provider) {
            'systech' => SerializationProvider::Systech,
            'unitrace' => SerializationProvider::UniTrace,
            'tracepharma' => SerializationProvider::TracePharma,
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
