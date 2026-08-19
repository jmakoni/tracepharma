<?php

namespace Tests\Feature\MasterData;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\AtpLicense;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ComplianceAlertNotification;
use App\Support\Auth\TenantRoleSeeder;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AlertExpiringAtpLicensesCommandTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $licenseIds = [];

    private ?int $siteId = null;

    #[Test]
    public function expired_and_expiring_licenses_are_digested_to_owners(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $owner = User::factory()->create();
            $owner->syncRoles([TenantRole::Owner->value]);

            $site = Site::factory()->owned()->create(['name' => 'HQ ATP Alert Site']);
            $this->siteId = (int) $site->getKey();

            $expired = AtpLicense::factory()->expired()->create([
                'site_id' => $site->id,
                'license_number' => 'ATP-EXPIRED-1',
            ]);
            $expiring = AtpLicense::factory()->expiringSoon()->create([
                'site_id' => $site->id,
                'license_number' => 'ATP-EXPIRING-1',
            ]);
            $inactive = AtpLicense::factory()->expired()->deactivated()->create([
                'site_id' => $site->id,
                'license_number' => 'ATP-INACTIVE-1',
            ]);
            $unknown = AtpLicense::factory()->unknownExpiry()->create([
                'site_id' => $site->id,
                'license_number' => 'ATP-UNKNOWN-1',
            ]);
            $this->licenseIds = [
                (int) $expired->getKey(),
                (int) $expiring->getKey(),
                (int) $inactive->getKey(),
                (int) $unknown->getKey(),
            ];

            Notification::fake();

            $this->artisan('compliance:alert-license-expiry', ['--tenant' => self::DEMO2_TENANT_ID])
                ->assertSuccessful();

            Notification::assertSentTo(
                $owner,
                ComplianceAlertNotification::class,
                function (ComplianceAlertNotification $notification): bool {
                    return str_contains($notification->message, 'ATP-EXPIRED-1')
                        && str_contains($notification->message, 'ATP-EXPIRING-1')
                        && str_contains($notification->message, 'ATP-UNKNOWN-1')
                        && ! str_contains($notification->message, 'ATP-INACTIVE-1')
                        && $notification->tenantId === self::DEMO2_TENANT_ID
                        && (bool) preg_match('#^/sites/\d+\?relation=1$#', $notification->actionPath);
                },
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function dry_run_does_not_notify(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = Site::factory()->owned()->create(['name' => 'Dry Run ATP Site']);
            $this->siteId = (int) $site->getKey();
            $license = AtpLicense::factory()->expired()->create(['site_id' => $site->id]);
            $this->licenseIds[] = (int) $license->getKey();

            Notification::fake();

            $this->artisan('compliance:alert-license-expiry', [
                '--tenant' => self::DEMO2_TENANT_ID,
                '--dry-run' => true,
            ])->assertSuccessful();

            Notification::assertNothingSent();
        } finally {
            $this->cleanup();
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
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
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

    private function cleanup(): void
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant !== null && ! tenancy()->initialized) {
            tenancy()->initialize($tenant);
        }

        if (! tenancy()->initialized) {
            return;
        }

        if ($this->licenseIds !== []) {
            AtpLicense::query()->whereIn('id', $this->licenseIds)->delete();
        }

        if ($this->siteId !== null) {
            Site::query()->whereKey($this->siteId)->delete();
        }

        $this->licenseIds = [];
        $this->siteId = null;
        tenancy()->end();
    }
}
