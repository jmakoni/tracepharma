<?php

namespace Tests\Feature;

use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\OnboardingWizard;
use App\Models\AtpLicense;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantOnboarding;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Go-live is gated on the party we receive from being authorized, not on our own dock:
 * our licence says nothing about theirs, so the checklist item and the mark-complete gate
 * both score upstream partner facilities.
 */
class TenantOnboardingUpstreamAtpGateTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    /**
     * A state no demo fixture holds a licence for, so "not ready" is the starting point.
     */
    private const RECEIVING_STATE = 'WY';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $deactivatedDeemedSiteIds = [];

    private ?TenantProfile $priorProfile = null;

    private ?string $priorReceivingState = null;

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    private ?int $priorDefaultReceiveSiteId = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private ?Carbon $priorDismissedAt = null;

    #[Test]
    public function the_gate_is_unsatisfied_while_no_upstream_partner_site_is_licensed(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useProfile($tenant, TenantProfile::Pharmacy);
            $this->isolateFromExistingUpstreamSites();

            $onboarding = TenantOnboarding::forTenant(tenant());

            $this->assertTrue($onboarding->requiresUpstreamAtp());
            $this->assertFalse($onboarding->isUpstreamAtpSatisfied());

            $item = $this->atpItem($onboarding);
            $this->assertFalse($item['done']);
            $this->assertSame('Upstream partner ATP ready for receiving state', $item['label']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function a_licensed_upstream_partner_site_satisfies_the_gate(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useProfile($tenant, TenantProfile::Pharmacy);

            $this->createUpstreamPartnerSite(PartnerType::Wholesaler, now()->addYears(2));

            $onboarding = TenantOnboarding::forTenant(tenant());

            $this->assertTrue($onboarding->isUpstreamAtpSatisfied());
            $this->assertTrue($this->atpItem($onboarding)['done']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function an_expiring_upstream_license_still_satisfies_the_gate(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useProfile($tenant, TenantProfile::Pharmacy);

            $this->createUpstreamPartnerSite(PartnerType::Wholesaler, now()->addDays(30));

            $this->assertTrue(TenantOnboarding::forTenant(tenant())->isUpstreamAtpSatisfied());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function an_expired_upstream_license_does_not_satisfy_the_gate(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useProfile($tenant, TenantProfile::Pharmacy);

            $this->isolateFromExistingUpstreamSites();
            $this->createUpstreamPartnerSite(PartnerType::Wholesaler, now()->subDay());

            $this->assertFalse(TenantOnboarding::forTenant(tenant())->isUpstreamAtpSatisfied());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function our_own_licensed_facility_does_not_satisfy_the_gate(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useProfile($tenant, TenantProfile::Pharmacy);
            $this->isolateFromExistingUpstreamSites();

            $ownDock = Site::query()->create([
                'trading_partner_id' => null,
                'name' => 'Own Receive Dock '.Str::random(6),
                'gln' => fake()->unique()->numerify('#############'),
                'is_organization_facility' => true,
                'is_active' => true,
                'country_code' => 'US',
            ]);
            $this->siteIds[] = (int) $ownDock->getKey();

            $this->createLicense($ownDock, now()->addYears(2));

            TenantSettings::forTenant(tenant())->saveOrganization([
                'gln' => '0366159000010',
                'company_prefix' => '0366159',
                'default_receive_site_id' => (int) $ownDock->getKey(),
            ]);

            $onboarding = TenantOnboarding::forTenant(tenant());

            $this->assertFalse($onboarding->isUpstreamAtpSatisfied());
            $this->assertFalse($this->atpItem($onboarding)['done']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function a_manufacturer_has_no_upstream_to_evidence(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useProfile($tenant, TenantProfile::Manufacturer);

            $onboarding = TenantOnboarding::forTenant(tenant());

            $this->assertFalse($onboarding->requiresUpstreamAtp());
            $this->assertTrue($onboarding->isUpstreamAtpSatisfied());
            $this->assertNotContains('atp_ready', array_column($onboarding->items(), 'id'));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function mark_complete_is_blocked_until_an_upstream_partner_is_ready(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useProfile($tenant, TenantProfile::Pharmacy);
            $this->isolateFromExistingUpstreamSites();
            $this->completeCriticalSetup();
            $this->actAsOwner();

            $this->assertTrue(TenantOnboarding::forTenant(tenant())->isCriticalComplete());

            Livewire::test(OnboardingWizard::class)->call('markComplete');

            $this->assertNull(
                TenantSettings::forTenant(tenant()->fresh())->onboardingDismissedAt(),
                'Setup was marked complete without an authorized upstream partner.',
            );

            $this->createUpstreamPartnerSite(PartnerType::Wholesaler, now()->addYears(2));

            Livewire::test(OnboardingWizard::class)->call('markComplete');

            $this->assertNotNull(
                TenantSettings::forTenant(tenant()->fresh())->onboardingDismissedAt(),
                'Setup stayed incomplete even though an upstream partner is licensed.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @return array{id: string, label: string, done: bool, href?: string}
     */
    private function atpItem(TenantOnboarding $onboarding): array
    {
        foreach ($onboarding->items() as $item) {
            if ($item['id'] === 'atp_ready') {
                return $item;
            }
        }

        $this->fail('Checklist has no atp_ready item.');
    }

    private function createUpstreamPartnerSite(PartnerType $type, Carbon $expiresAt): Site
    {
        $partner = TradingPartner::query()->create([
            'name' => 'Upstream ATP Partner '.Str::random(6),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => $type,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->partnerIds[] = (int) $partner->getKey();

        $site = Site::query()->create([
            'trading_partner_id' => $partner->getKey(),
            'name' => 'Upstream DC '.Str::random(6),
            'gln' => fake()->unique()->numerify('#############'),
            'is_active' => true,
            'country_code' => 'US',
        ]);
        $this->siteIds[] = (int) $site->getKey();

        $this->createLicense($site, $expiresAt);

        return $site;
    }

    private function isolateFromExistingUpstreamSites(): void
    {
        $ids = Site::query()
            ->where('is_active', true)
            ->whereHas('tradingPartner', fn ($partners) => $partners->whereIn('partner_type', [
                PartnerType::Manufacturer,
                PartnerType::Wholesaler,
            ]))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return;
        }

        Site::query()->whereIn('id', $ids)->update(['is_active' => false]);
        $this->deactivatedDeemedSiteIds = $ids;
    }

    private function createLicense(Site $site, Carbon $expiresAt): AtpLicense
    {
        return AtpLicense::query()->create([
            'site_id' => $site->getKey(),
            'facility_type' => FacilityType::Wdd,
            'license_number' => 'UPSTREAM-'.Str::upper(Str::random(8)),
            'license_state' => self::RECEIVING_STATE,
            'license_expiration_date' => $expiresAt,
            'reporting_year' => (int) now()->year,
            'is_active' => true,
        ]);
    }

    private function completeCriticalSetup(): void
    {
        $site = Site::query()->create([
            'name' => 'Onboarding Receive Site '.Str::random(6),
            'gln' => fake()->unique()->numerify('#############'),
            'is_active' => true,
            'is_organization_facility' => true,
            'country_code' => 'US',
        ]);
        $this->siteIds[] = (int) $site->getKey();

        $settings = TenantSettings::forTenant(tenant());
        $settings->saveOrganization([
            'gln' => '0366159000010',
            'company_prefix' => '0366159',
            'default_receive_site_id' => (int) $site->getKey(),
        ]);
        $settings->setOnboardingDismissedAt(null);
        tenant()->save();
    }

    private function actAsOwner(): User
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));

        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        $this->actingAs($user);

        return $user;
    }

    private function useProfile(Tenant $tenant, TenantProfile $profile): void
    {
        $tenant->forceFill([
            'profile' => $profile,
            'receiving_state' => self::RECEIVING_STATE,
        ])->save();

        tenancy()->end();
        tenancy()->initialize($tenant->fresh());
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

        $settings = TenantSettings::forTenant($tenant);
        $this->priorProfile = $tenant->profile;
        $this->priorReceivingState = $settings->receivingState();
        $this->priorGln = $settings->gln();
        $this->priorCompanyPrefix = $settings->companyPrefix();
        $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        $this->priorDismissedAt = $settings->onboardingDismissedAt();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->deactivatedDeemedSiteIds !== []) {
                Site::query()->whereIn('id', $this->deactivatedDeemedSiteIds)->update(['is_active' => true]);
                $this->deactivatedDeemedSiteIds = [];
            }

            if ($this->siteIds !== []) {
                AtpLicense::query()->whereIn('site_id', $this->siteIds)->delete();
                Site::query()->whereIn('id', $this->siteIds)->delete();
                $this->siteIds = [];
            }

            if ($this->partnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
                $this->partnerIds = [];
            }

            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
                $this->userIds = [];
            }

            $restored = $tenant->fresh() ?? $tenant;

            if ($this->priorProfile !== null) {
                $restored->forceFill(['profile' => $this->priorProfile])->save();
            }

            $settings = TenantSettings::forTenant($restored);
            $settings->setReceivingState($this->priorReceivingState);
            $settings->saveOrganization([
                'gln' => $this->priorGln,
                'company_prefix' => $this->priorCompanyPrefix,
                'default_receive_site_id' => $this->priorDefaultReceiveSiteId,
                'default_ship_from_site_id' => $this->priorDefaultShipFromSiteId,
            ]);
            $settings->setOnboardingDismissedAt($this->priorDismissedAt);
            $restored->save();

            tenancy()->end();
        }

        $this->priorProfile = null;
        $this->priorReceivingState = null;
        $this->priorGln = null;
        $this->priorCompanyPrefix = null;
        $this->priorDefaultReceiveSiteId = null;
        $this->priorDefaultShipFromSiteId = null;
        $this->priorDismissedAt = null;
    }
}
