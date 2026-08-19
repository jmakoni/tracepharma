<?php

declare(strict_types=1);

namespace Tests\Feature\Epcis\Hub;

use App\Actions\Integrations\RegisterEpcisHubRoute;
use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\EpcisHubRoute;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class RegisterEpcisHubRouteGateTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const DEMO2_GLN = '0366159000010';

    private static bool $demo2TenantReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        app(EpcisHubPlatformConfig::class)->setProviders('stage', ['systech', 'unitrace']);
    }

    #[Test]
    public function tenant_without_hub_providers_cannot_register(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        $tenant->forceFill([
            'gln' => self::DEMO2_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => null,
        ])->save();

        $connection = $tenant->run(fn (): InboundConnection => InboundConnection::query()->create([
            'name' => 'Hub gate test',
            'serialization_provider' => SerializationProvider::Systech,
            'transport' => InboundTransport::Https,
            'is_active' => true,
        ]));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('not enabled for this tenant');

            $tenant->run(fn () => app(RegisterEpcisHubRoute::class)->register($connection));
        } finally {
            $tenant->run(fn () => $connection->delete());
            tenancy()->end();
        }
    }

    #[Test]
    public function tenant_without_inbound_environment_cannot_register(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        $tenant->forceFill([
            'gln' => self::DEMO2_GLN,
            'inbound_environment' => null,
            'hub_providers' => ['systech'],
        ])->save();

        $connection = $tenant->run(fn (): InboundConnection => InboundConnection::query()->create([
            'name' => 'Hub gate env test',
            'serialization_provider' => SerializationProvider::Systech,
            'transport' => InboundTransport::Https,
            'is_active' => true,
        ]));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('inbound environment must be set');

            $tenant->run(fn () => app(RegisterEpcisHubRoute::class)->register($connection));
        } finally {
            $tenant->run(fn () => $connection->delete());
            tenancy()->end();
        }
    }

    #[Test]
    public function tenant_with_matching_env_and_providers_can_register(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        $tenant->forceFill([
            'gln' => self::DEMO2_GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => ['systech'],
        ])->save();

        $connection = $tenant->run(fn (): InboundConnection => InboundConnection::query()->create([
            'name' => 'Hub gate success',
            'serialization_provider' => SerializationProvider::Systech,
            'transport' => InboundTransport::Https,
            'is_active' => true,
        ]));

        try {
            $route = $tenant->run(fn (): EpcisHubRoute => app(RegisterEpcisHubRoute::class)->register($connection));

            $this->assertSame($tenant->getKey(), $route->tenant_id);
            $this->assertSame('systech', $route->provider);
            $this->assertSame(self::DEMO2_GLN, $route->gln);
        } finally {
            EpcisHubRoute::query()
                ->where('tenant_id', $tenant->id)
                ->where('provider', 'systech')
                ->delete();

            $tenant->run(fn () => $connection->delete());
            tenancy()->end();
        }
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'gln' => self::DEMO2_GLN,
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }
}
