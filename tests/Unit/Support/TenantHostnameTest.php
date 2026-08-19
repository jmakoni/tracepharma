<?php

namespace Tests\Unit\Support;

use App\Support\TenantHostname;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantHostnameTest extends TestCase
{
    #[Test]
    public function admin2_slug_is_reserved_when_it_matches_the_admin_host(): void
    {
        config(['tracepharma.admin_domain' => 'admin2.localhost']);

        $this->assertTrue(TenantHostname::isReservedSlug('admin2'));
        $this->expectException(\RuntimeException::class);
        TenantHostname::assertProvisionableSlug('admin2');
    }

    #[Test]
    public function dns_slugs_reject_underscores(): void
    {
        $this->expectException(\RuntimeException::class);
        TenantHostname::assertProvisionableSlug('acme_pharmacy');
    }

    #[Test]
    public function ordinary_dns_slugs_are_allowed(): void
    {
        TenantHostname::assertProvisionableSlug('acme-pharmacy');
        $this->assertFalse(TenantHostname::isReservedSlug('acme-pharmacy'));
    }

    #[Test]
    public function pair_hosts_use_the_platform_base_domain(): void
    {
        config([
            'tracepharma.platform_base_domain' => 'tracepharma.io',
            'tracepharma.tenant_environment' => 'prod',
        ]);

        $this->assertSame('acme.stage.tracepharma.io', TenantHostname::forSlug('acme', 'stage'));
        $this->assertSame('acme.prod.tracepharma.io', TenantHostname::forSlug('acme', 'prod'));
        $this->assertSame('acme.prod.tracepharma.io', TenantHostname::forCurrentEnvironment('acme'));
        $this->assertSame([
            'acme.stage.tracepharma.io',
            'acme.prod.tracepharma.io',
        ], TenantHostname::pairForSlug('acme'));
    }

    #[Test]
    public function stage_and_prod_slugs_are_reserved(): void
    {
        $this->assertTrue(TenantHostname::isReservedSlug('stage'));
        $this->assertTrue(TenantHostname::isReservedSlug('prod'));
        $this->assertTrue(TenantHostname::isReservedSlug('admin'));
        $this->assertTrue(TenantHostname::isReservedSlug('www'));

        $this->expectException(\RuntimeException::class);
        TenantHostname::assertProvisionableSlug('stage');
    }

    #[Test]
    public function looks_like_pair_host_matches_stage_and_prod_tenant_domains(): void
    {
        config(['tracepharma.platform_base_domain' => 'tracepharma.io']);

        $this->assertTrue(TenantHostname::looksLikePairHost('acme.stage.tracepharma.io'));
        $this->assertTrue(TenantHostname::looksLikePairHost('acme.prod.tracepharma.io'));
        $this->assertFalse(TenantHostname::looksLikePairHost('stage.test.tracepharma.io'));
        $this->assertFalse(TenantHostname::looksLikePairHost('hub.stage.tracepharma.io.example.com'));
    }

    #[Test]
    public function reserved_hosts_include_central_admin_and_environment_labels(): void
    {
        config([
            'tracepharma.platform_base_domain' => 'tracepharma.io',
            'tracepharma.central_domain' => 'app.tracepharma.io',
            'tracepharma.admin_domain' => 'admin.tracepharma.io',
            'tenancy.central_domains' => ['legacy-central.test'],
        ]);

        $this->assertTrue(TenantHostname::isReservedHost('app.tracepharma.io'));
        $this->assertTrue(TenantHostname::isReservedHost('admin.tracepharma.io'));
        $this->assertTrue(TenantHostname::isReservedHost('legacy-central.test'));
        $this->assertTrue(TenantHostname::isReservedHost('stage.tracepharma.io'));
        $this->assertTrue(TenantHostname::isReservedHost('prod.tracepharma.io'));
        $this->assertFalse(TenantHostname::isReservedHost('acme.prod.tracepharma.io'));
    }
}
