<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Support\Compliance\ComplianceAlertMetrics;
use App\Support\Compliance\ExpiryAlertMetrics;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExpiryAlertMetricsIntegrationTest extends TestCase
{
    private const TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private static bool $tenantReady = false;

    #[Test]
    public function expiry_counts_and_alert_metrics_include_expiry_keys(): void
    {
        $this->initializeTenant();

        try {
            $counts = app(ExpiryAlertMetrics::class)->counts();
            $this->assertArrayHasKey('expired', $counts);
            $this->assertArrayHasKey('soon_30', $counts);
            $this->assertArrayHasKey('soon_90', $counts);

            $alerts = app(ComplianceAlertMetrics::class)->alerts(null);
            $this->assertIsArray($alerts);
        } finally {
            tenancy()->end();
        }
    }

    private function initializeTenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => 'tenant_demo2_internal_vatengi_com',
            ]));
            $tenant->domains()->create(['domain' => 'demo2.internal.vatengi.com']);
        }

        if (! self::$tenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$tenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }
}
