<?php

namespace Tests\Feature\MasterData;

use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\AtpLicense;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
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

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function expired_and_expiring_licenses_are_digested_to_owners(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $owner = User::factory()->create();
            $owner->syncRoles([TenantRole::Owner->value]);
            $this->userIds[] = (int) $owner->getKey();

            $site = Site::factory()->owned()->create([
                'name' => 'HQ ATP Alert Site',
                'state' => 'IL',
            ]);
            $this->siteIds[] = (int) $site->getKey();

            $expired = AtpLicense::factory()->expired()->create([
                'site_id' => $site->id,
                'license_number' => 'ATP-EXPIRED-1',
                'license_state' => 'IL',
            ]);
            $expiring = AtpLicense::factory()->expiringSoon()->create([
                'site_id' => $site->id,
                'license_number' => 'ATP-EXPIRING-1',
                'license_state' => 'IL',
            ]);
            $inactive = AtpLicense::factory()->expired()->deactivated()->create([
                'site_id' => $site->id,
                'license_number' => 'ATP-INACTIVE-1',
                'license_state' => 'IL',
            ]);
            $unknown = AtpLicense::factory()->unknownExpiry()->create([
                'site_id' => $site->id,
                'license_number' => 'ATP-UNKNOWN-1',
                'license_state' => 'IL',
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
            $site = Site::factory()->owned()->create([
                'name' => 'Dry Run ATP Site',
                'state' => 'IL',
            ]);
            $this->siteIds[] = (int) $site->getKey();
            $license = AtpLicense::factory()->expired()->create([
                'site_id' => $site->id,
                'license_state' => 'IL',
            ]);
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

    #[Test]
    public function manufacturer_hq_license_is_not_alerted(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $owner = User::factory()->create();
            $owner->syncRoles([TenantRole::Owner->value]);
            $this->userIds[] = (int) $owner->getKey();

            $org = Site::factory()->owned()->create(['name' => 'Tenant HQ', 'state' => 'IL']);
            $this->siteIds[] = (int) $org->getKey();

            $partner = TradingPartner::factory()->create([
                'name' => 'Xttrium HQ Partner',
                'partner_type' => PartnerType::Manufacturer,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $hq = Site::factory()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'XTTRIUM LABORATORIES, INC. - Glenview',
                'is_headquarters' => true,
                'state' => 'IL',
            ]);
            $this->siteIds[] = (int) $hq->getKey();

            $license = AtpLicense::factory()->expiringSoon()->create([
                'site_id' => $hq->id,
                'license_number' => 'MFR-HQ-IL-1',
                'license_state' => 'IL',
            ]);
            $this->licenseIds[] = (int) $license->getKey();

            Notification::fake();

            $this->artisan('compliance:alert-license-expiry', ['--tenant' => self::DEMO2_TENANT_ID])
                ->assertSuccessful();

            $this->assertLicenseNumberNotAlertedTo($owner, 'MFR-HQ-IL-1');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function manufacturer_non_hq_license_in_tenant_state_is_alerted(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $owner = User::factory()->create();
            $owner->syncRoles([TenantRole::Owner->value]);
            $this->userIds[] = (int) $owner->getKey();

            $org = Site::factory()->owned()->create(['name' => 'Tenant HQ', 'state' => 'IL']);
            $this->siteIds[] = (int) $org->getKey();

            $partner = TradingPartner::factory()->create([
                'name' => 'Mfr With DC',
                'partner_type' => PartnerType::Manufacturer,
                // Distinct address so DECRS plant match does not fire on the DC.
                'street_address' => '1 Plant Road',
                'city' => 'Glenview',
                'state' => 'IL',
                'zipcode' => '60025',
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $dc = Site::factory()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'Manufacturer DC',
                'is_headquarters' => false,
                'street_address' => '99 Warehouse Blvd',
                'city' => 'Chicago',
                'state' => 'IL',
                'zipcode' => '60601',
                'fda_wdd_facility_id' => null,
                'fda_establishment_id' => null,
            ]);
            $this->siteIds[] = (int) $dc->getKey();

            $license = AtpLicense::factory()->expiringSoon()->create([
                'site_id' => $dc->id,
                'license_number' => 'DC-IL-1',
                'license_state' => 'IL',
            ]);
            $this->licenseIds[] = (int) $license->getKey();

            $doc = \App\Models\Epcis\EpcisDocument::query()->create([
                'document_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'direction' => 'inbound',
                'status' => 'validated',
                'ship_from_site_id' => $dc->id,
                'original_filename' => 'dc-ship-from-proof.xml',
                'file_sha256' => hash('sha256', 'dc-ship-from-proof-'.uniqid()),
                'creation_date' => now(),
                'received_at' => now(),
            ]);
            // Soft cleanup via delete after test if needed — document id tracked loosely
            unset($doc);

            Notification::fake();

            $this->artisan('compliance:alert-license-expiry', ['--tenant' => self::DEMO2_TENANT_ID])
                ->assertSuccessful();

            Notification::assertSentTo(
                $owner,
                ComplianceAlertNotification::class,
                fn (ComplianceAlertNotification $n): bool => str_contains($n->message, 'DC-IL-1'),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function wholesaler_license_in_tenant_state_is_alerted(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $owner = User::factory()->create();
            $owner->syncRoles([TenantRole::Owner->value]);
            $this->userIds[] = (int) $owner->getKey();

            $org = Site::factory()->owned()->create(['name' => 'Tenant HQ', 'state' => 'IL']);
            $this->siteIds[] = (int) $org->getKey();

            $partner = TradingPartner::factory()->create([
                'partner_type' => PartnerType::Wholesaler,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $site = Site::factory()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'Wholesaler Depot',
                'state' => 'IL',
            ]);
            $this->siteIds[] = (int) $site->getKey();

            $license = AtpLicense::factory()->expired()->create([
                'site_id' => $site->id,
                'license_number' => 'WHS-IL-1',
                'license_state' => 'IL',
            ]);
            $this->licenseIds[] = (int) $license->getKey();

            Notification::fake();

            $this->artisan('compliance:alert-license-expiry', ['--tenant' => self::DEMO2_TENANT_ID])
                ->assertSuccessful();

            Notification::assertSentTo(
                $owner,
                ComplianceAlertNotification::class,
                fn (ComplianceAlertNotification $n): bool => str_contains($n->message, 'WHS-IL-1'),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function wholesaler_license_outside_tenant_states_is_not_alerted(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $owner = User::factory()->create();
            $owner->syncRoles([TenantRole::Owner->value]);
            $this->userIds[] = (int) $owner->getKey();

            $org = Site::factory()->owned()->create(['name' => 'Tenant HQ', 'state' => 'IL']);
            $this->siteIds[] = (int) $org->getKey();

            // Synthetic state code outside any real US footprint.
            $foreignState = 'ZZ';

            $partner = TradingPartner::factory()->create([
                'partner_type' => PartnerType::Wholesaler,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $site = Site::factory()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'Wholesaler Foreign Depot',
                'state' => 'AK',
            ]);
            $this->siteIds[] = (int) $site->getKey();

            $license = AtpLicense::factory()->expiringSoon()->create([
                'site_id' => $site->id,
                'license_number' => 'FOREIGN-STATE-1',
                'license_state' => $foreignState,
            ]);
            $this->licenseIds[] = (int) $license->getKey();

            Notification::fake();

            $this->artisan('compliance:alert-license-expiry', ['--tenant' => self::DEMO2_TENANT_ID])
                ->assertSuccessful();

            $this->assertLicenseNumberNotAlertedTo($owner, 'FOREIGN-STATE-1');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function org_own_license_in_tenant_state_is_alerted(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $owner = User::factory()->create();
            $owner->syncRoles([TenantRole::Owner->value]);
            $this->userIds[] = (int) $owner->getKey();

            $org = Site::factory()->owned()->create([
                'name' => 'Org Pharmacy',
                'state' => 'IL',
            ]);
            $this->siteIds[] = (int) $org->getKey();

            $license = AtpLicense::factory()->expiringSoon()->create([
                'site_id' => $org->id,
                'license_number' => 'ORG-IL-1',
                'license_state' => 'IL',
            ]);
            $this->licenseIds[] = (int) $license->getKey();

            Notification::fake();

            $this->artisan('compliance:alert-license-expiry', ['--tenant' => self::DEMO2_TENANT_ID])
                ->assertSuccessful();

            Notification::assertSentTo(
                $owner,
                ComplianceAlertNotification::class,
                fn (ComplianceAlertNotification $n): bool => str_contains($n->message, 'ORG-IL-1'),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function manufacturer_dc_without_inbound_ship_from_is_not_alerted(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $owner = User::factory()->create();
            $owner->syncRoles([TenantRole::Owner->value]);
            $this->userIds[] = (int) $owner->getKey();

            $org = Site::factory()->owned()->create(['name' => 'Tenant HQ', 'state' => 'IL']);
            $this->siteIds[] = (int) $org->getKey();

            $partner = TradingPartner::factory()->create([
                'name' => 'Mfr DC No Proof',
                'partner_type' => PartnerType::Manufacturer,
                'street_address' => '1 Plant Road',
                'city' => 'Glenview',
                'state' => 'IL',
                'zipcode' => '60025',
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $dc = Site::factory()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'Manufacturer DC No Proof',
                'is_headquarters' => false,
                'street_address' => '88 Warehouse Blvd',
                'city' => 'Chicago',
                'state' => 'IL',
                'zipcode' => '60602',
            ]);
            $this->siteIds[] = (int) $dc->getKey();

            $license = AtpLicense::factory()->expiringSoon()->create([
                'site_id' => $dc->id,
                'license_number' => 'DC-NO-PROOF-1',
                'license_state' => 'IL',
            ]);
            $this->licenseIds[] = (int) $license->getKey();

            Notification::fake();

            $this->artisan('compliance:alert-license-expiry', ['--tenant' => self::DEMO2_TENANT_ID])
                ->assertSuccessful();

            $this->assertLicenseNumberNotAlertedTo($owner, 'DC-NO-PROOF-1');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function multi_country_license_in_matching_org_footprint_is_alerted(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $owner = User::factory()->create();
            $owner->syncRoles([TenantRole::Owner->value]);
            $this->userIds[] = (int) $owner->getKey();

            $org = Site::factory()->owned()->create([
                'name' => 'Canada Org Site',
                'country_code' => 'CA',
                'state' => 'ON',
            ]);
            $this->siteIds[] = (int) $org->getKey();

            $partner = TradingPartner::factory()->create([
                'partner_type' => PartnerType::Wholesaler,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $site = Site::factory()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'CA Wholesaler',
                'state' => 'ON',
                'country_code' => 'CA',
            ]);
            $this->siteIds[] = (int) $site->getKey();

            $license = AtpLicense::factory()->expiringSoon()->create([
                'site_id' => $site->id,
                'license_number' => 'CA-ON-1',
                'license_country' => 'CA',
                'license_state' => 'ON',
            ]);
            $this->licenseIds[] = (int) $license->getKey();

            Notification::fake();

            $this->artisan('compliance:alert-license-expiry', ['--tenant' => self::DEMO2_TENANT_ID])
                ->assertSuccessful();

            Notification::assertSentTo(
                $owner,
                ComplianceAlertNotification::class,
                fn (ComplianceAlertNotification $n): bool => str_contains($n->message, 'CA-ON-1'),
            );
        } finally {
            $this->cleanup();
        }
    }

    private function assertLicenseNumberNotAlertedTo(User $owner, string $licenseNumber): void
    {
        $messages = Notification::sent($owner, ComplianceAlertNotification::class)
            ->map(fn (ComplianceAlertNotification $notification): string => $notification->message)
            ->implode("\n");

        $this->assertStringNotContainsString(
            $licenseNumber,
            $messages,
            "License {$licenseNumber} should not appear in owner ATP expiry alerts.",
        );
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

        if ($this->siteIds !== []) {
            Site::query()->whereKey($this->siteIds)->delete();
        }

        if ($this->partnerIds !== []) {
            TradingPartner::query()->whereKey($this->partnerIds)->delete();
        }

        if ($this->userIds !== []) {
            User::query()->whereKey($this->userIds)->delete();
        }

        $this->licenseIds = [];
        $this->siteIds = [];
        $this->partnerIds = [];
        $this->userIds = [];
        tenancy()->end();
    }
}
