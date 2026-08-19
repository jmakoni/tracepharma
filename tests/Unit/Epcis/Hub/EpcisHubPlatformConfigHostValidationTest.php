<?php

declare(strict_types=1);

namespace Tests\Unit\Epcis\Hub;

use App\Models\Tenant;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\PlatformSettings;
use App\Support\TenantHostname;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;

class EpcisHubPlatformConfigHostValidationTest extends TestCase
{
    /** @var list<string> */
    private array $orphanTenantIds = [];

    protected function tearDown(): void
    {
        if ($this->orphanTenantIds !== []) {
            Domain::query()->whereIn('tenant_id', $this->orphanTenantIds)->delete();
            Tenant::withoutEvents(fn () => Tenant::query()->whereIn('id', $this->orphanTenantIds)->delete());
        }

        PlatformSettings::forget('epcis_hub.stage.host');
        PlatformSettings::forget('epcis_hub.prod.host');

        parent::tearDown();
    }

    #[Test]
    public function set_host_rejects_existing_tenant_domains(): void
    {
        $slug = 'hub-config-conflict-'.Str::lower(Str::random(6));
        $tenantId = (string) Str::uuid();
        $this->orphanTenantIds[] = $tenantId;
        $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
            'id' => $tenantId,
            'name' => 'Hub config conflict orphan',
            'status' => 'active',
            'tenancy_db_name' => 'tenant_hubcfg_'.substr(str_replace('-', '', $tenantId), 0, 16),
        ]));
        $domain = TenantHostname::forSlug($slug, 'prod');
        $tenant->domains()->create(['domain' => $domain]);

        $config = app(EpcisHubPlatformConfig::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($domain);
        $config->setHost('prod', $domain);
    }

    #[Test]
    public function set_host_rejects_tenant_pair_hostname_pattern(): void
    {
        $pairHost = TenantHostname::forSlug('acme-pharmacy', 'stage');
        $config = app(EpcisHubPlatformConfig::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tenant pair hostname pattern');
        $config->setHost('stage', $pairHost);
    }

    #[Test]
    public function set_host_rejects_reserved_central_and_platform_hosts(): void
    {
        config([
            'tracepharma.platform_base_domain' => 'tracepharma.io',
            'tracepharma.central_domain' => 'app.tracepharma.io',
            'tracepharma.admin_domain' => 'admin.tracepharma.io',
            'tracepharma.marketing_domain' => 'www.tracepharma.io',
            'tenancy.central_domains' => ['legacy-central.test'],
        ]);

        $config = app(EpcisHubPlatformConfig::class);

        foreach ([
            'app.tracepharma.io',
            'admin.tracepharma.io',
            'www.tracepharma.io',
            'legacy-central.test',
            'stage.tracepharma.io',
            'prod.tracepharma.io',
        ] as $host) {
            try {
                $config->setHost('prod', $host);
                $this->fail("Expected reserved host rejection for {$host}.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('reserved for central platform use', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function assert_hub_host_allowed_rejects_reserved_hosts(): void
    {
        config(['tracepharma.admin_domain' => 'admin2.example.test']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved for central platform use');

        EpcisHubPlatformConfig::assertHubHostAllowed('admin2.example.test');
    }
}
