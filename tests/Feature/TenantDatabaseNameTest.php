<?php

namespace Tests\Feature;

use App\Support\TenantDatabaseName;
use PHPUnit\Framework\TestCase;

class TenantDatabaseNameTest extends TestCase
{
    public function test_from_domain_replaces_dots_and_dashes(): void
    {
        $this->assertSame(
            'tenant_demo2_internal_vatengi_com',
            TenantDatabaseName::fromDomain('demo2.internal.vatengi.com'),
        );
        $this->assertSame(
            'tenant_demo2_localhost',
            TenantDatabaseName::fromDomain('Demo2.Localhost'),
        );
        $this->assertSame(
            'tenant_my_tenant_example_com',
            TenantDatabaseName::fromDomain('my-tenant.example.com'),
        );
    }
}
