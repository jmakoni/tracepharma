<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Notifications\ComplianceAlertNotification;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AlertCenterDigestCommandTest extends TestCase
{
    private const TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private static bool $tenantReady = false;

    #[Test]
    public function digest_command_notifies_compliance_contact_when_alerts_exist(): void
    {
        $tenant = $this->initializeTenant();

        try {
            Notification::fake();

            TenantSettings::forTenant($tenant)
                ->setAlertDigestEnabled(true)
                ->setAlertDigestFrequency('daily')
                ->setComplianceContactEmail('compliance-digest-'.substr((string) str()->uuid(), 0, 8).'@demo.test')
                ->setReceivingState(null);
            $tenant->save();

            $this->artisan('compliance:alert-center-digest', [
                '--tenant' => self::TENANT_ID,
                '--force' => true,
            ])->assertSuccessful();

            Notification::assertSentOnDemand(ComplianceAlertNotification::class);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function digest_command_skips_when_disabled(): void
    {
        $tenant = $this->initializeTenant();

        try {
            Notification::fake();

            TenantSettings::forTenant($tenant)->setAlertDigestEnabled(false);
            $tenant->save();

            $this->artisan('compliance:alert-center-digest', [
                '--tenant' => self::TENANT_ID,
                '--force' => true,
            ])->assertSuccessful();

            Notification::assertNothingSent();
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
