<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Tenant;
use App\Support\TenantAppUrl;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantAppUrlTest extends TestCase
{
    #[Test]
    public function temporary_signed_route_uses_tenant_domain(): void
    {
        $domainsQuery = Mockery::mock();
        $domainsQuery->shouldReceive('orderBy')->with('id')->andReturnSelf();
        $domainsQuery->shouldReceive('value')->with('domain')->andReturn('demo2.internal.vatengi.com');

        $tenant = Mockery::mock(Tenant::class);
        $tenant->shouldReceive('domains')->andReturn($domainsQuery);

        $url = TenantAppUrl::temporarySignedRoute(
            'tenant.data-export.download',
            now()->addHour(),
            ['export' => '15301cc8-dd77-4d52-89b2-f23c87fe4a2d'],
            $tenant,
        );

        $this->assertStringContainsString('demo2.internal.vatengi.com/exports/', $url);
        $this->assertStringContainsString('signature=', $url);
    }
}
