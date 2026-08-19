<?php

namespace Tests\Feature;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Pages\OnboardingWizard;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OnboardingWizardRedirectTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const ONBOARDING_REDIRECT_SESSION_KEY = 'filament.app.onboarding_wizard_redirected';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    private ?TenantProfile $priorProfile = null;

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    private ?int $priorDefaultReceiveSiteId = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private mixed $priorOnboardingDismissedAt = null;

    #[Test]
    public function dashboard_redirects_to_onboarding_wizard_when_critical_incomplete_and_not_dismissed(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => null,
                'default_receive_site_id' => null,
                'default_ship_from_site_id' => null,
            ]);
            TenantSettings::forTenant($tenant)->setOnboardingDismissedAt(null);
            $tenant->save();

            session()->forget(self::ONBOARDING_REDIRECT_SESSION_KEY);

            $user = $this->createOwner();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(Dashboard::class)
                ->assertRedirect(OnboardingWizard::getUrl(panel: 'app'));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function dashboard_does_not_redirect_after_onboarding_dismissed(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => null,
                'default_receive_site_id' => null,
                'default_ship_from_site_id' => null,
            ]);
            TenantSettings::forTenant($tenant)->setOnboardingDismissedAt(now());
            $tenant->save();

            session()->forget(self::ONBOARDING_REDIRECT_SESSION_KEY);

            $user = $this->createOwner();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(Dashboard::class)->assertNoRedirect();
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function pharmacy_wizard_copy_is_receive_focused_without_wholesaler_language(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::Pharmacy);

            $user = $this->createOwner();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $component = Livewire::test(OnboardingWizard::class);
            $subheading = (string) $component->instance()->getSubheading();

            $this->assertStringNotContainsStringIgnoringCase('wholesaler', $subheading);
            $this->assertStringNotContainsStringIgnoringCase('ship to pharmacies', $subheading);
            $this->assertStringContainsStringIgnoringCase('pharmacy', $subheading);
            $this->assertStringContainsStringIgnoringCase('receiv', $subheading);

            $component
                ->assertSee('Pharmacy setup:')
                ->assertDontSee('Drug wholesaler setup:')
                ->assertDontSee('ship to pharmacies');

            $descriptions = collect($component->instance()->checklistItems())
                ->pluck('description', 'id');

            $this->assertNotEmpty($descriptions->get('org_gln'));
            $this->assertStringContainsStringIgnoringCase('pharmacy', (string) $descriptions->get('org_gln'));
            $this->assertArrayNotHasKey('downstream_partner', $descriptions->all());
            $this->assertArrayNotHasKey('default_ship_from_site', $descriptions->all());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function dashboard_does_not_redirect_when_critical_onboarding_is_complete(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::Pharmacy);

            $site = Site::query()->create([
                'name' => 'Onboarding Redirect HQ '.Str::random(6),
                'gln' => '0366159000034',
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $site->getKey();

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0366159000010',
                'company_prefix' => '0366159',
                'default_receive_site_id' => (int) $site->getKey(),
                'default_ship_from_site_id' => null,
            ]);
            TenantSettings::forTenant($tenant)->setOnboardingDismissedAt(null);
            $tenant->save();

            session()->forget(self::ONBOARDING_REDIRECT_SESSION_KEY);

            $user = $this->createOwner();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(Dashboard::class)->assertNoRedirect();
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function createOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);

        return $user;
    }

    private function setProfile(Tenant $tenant, TenantProfile $profile): void
    {
        $tenant->forceFill(['profile' => $profile])->save();
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

        Filament::setCurrentPanel(Filament::getPanel('app'));

        $settings = TenantSettings::forTenant($tenant);
        $this->priorProfile = $tenant->profile;
        $this->priorGln = $settings->gln();
        $this->priorCompanyPrefix = $settings->companyPrefix();
        $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        $this->priorOnboardingDismissedAt = $settings->onboardingDismissedAt();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
                $this->siteIds = [];
            }

            $restored = $tenant->fresh() ?? $tenant;
            if ($this->priorProfile !== null) {
                $restored->forceFill(['profile' => $this->priorProfile])->save();
            }

            $settings = TenantSettings::forTenant($restored);
            $settings->saveOrganization([
                'gln' => $this->priorGln,
                'company_prefix' => $this->priorCompanyPrefix,
                'default_receive_site_id' => $this->priorDefaultReceiveSiteId,
                'default_ship_from_site_id' => $this->priorDefaultShipFromSiteId,
            ]);
            $settings->setOnboardingDismissedAt($this->priorOnboardingDismissedAt);
            $restored->save();
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        session()->forget(self::ONBOARDING_REDIRECT_SESSION_KEY);

        $this->priorProfile = null;
        $this->priorGln = null;
        $this->priorCompanyPrefix = null;
        $this->priorDefaultReceiveSiteId = null;
        $this->priorDefaultShipFromSiteId = null;
        $this->priorOnboardingDismissedAt = null;
    }
}
