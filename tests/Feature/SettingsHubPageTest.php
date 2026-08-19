<?php

namespace Tests\Feature;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\SettingsHub;
use App\Models\OutboundConnection;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantOnboarding;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SettingsHubPageTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SITE_GLN = '0366159000034';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    private ?TenantProfile $priorProfile = null;

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    private ?int $priorDefaultReceiveSiteId = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private mixed $priorOutboundDeferredAt = null;

    /** @var list<int> */
    private array $deactivatedOutboundConnectionIds = [];

    #[Test]
    public function settings_hub_shows_readiness_and_sectioned_configure_links(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $user = $this->createOwner();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $component = Livewire::test(SettingsHub::class);

            $component
                ->assertSee('Go-live readiness')
                ->assertSee('Critical')
                ->assertSee('Recommended')
                ->assertSee('Configure')
                ->assertSee('Organization & sites')
                ->assertSee('Organization')
                ->assertSee('Sites & ATP')
                ->assertSee('Your locations, GLNs, and ATP licenses.')
                ->assertSee('Trading partners')
                ->assertDontSee('Receiving preferences')
                ->assertDontSee('Shipping preferences')
                ->assertDontSee('Outbound integrations');

            $sections = $component->instance()->cardSections();
            $labels = collect($sections)
                ->flatMap(fn (array $section) => collect($section['cards'])->pluck('label'))
                ->all();

            $this->assertContains('Organization', $labels);
            $this->assertNotContains('Receiving preferences', $labels);
            $this->assertNotContains('Operations Hub', $labels);
            $this->assertSame(
                1,
                collect($labels)->filter(fn (string $label): bool => $label === 'Organization')->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function pharmacy_checklist_shows_profile_aware_helper_descriptions(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::Pharmacy);

            $user = $this->createOwner();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $component = Livewire::test(SettingsHub::class);
            $byId = collect($component->instance()->checklistItems())->keyBy('id');

            $this->assertTrue($byId->has('org_gln'));
            $this->assertNotEmpty($byId['org_gln']['description'] ?? null);
            $this->assertStringContainsStringIgnoringCase('pharmacy', $byId['org_gln']['description']);
            $this->assertStringNotContainsStringIgnoringCase('wholesaler on EPCIS', $byId['org_gln']['description']);
            $this->assertFalse($byId->has('downstream_partner'));
            $this->assertFalse($byId->has('default_ship_from_site'));

            $visibleDescription = collect($component->instance()->incompleteChecklistItems())
                ->pluck('description')
                ->filter()
                ->first();

            $this->assertNotNull($visibleDescription);
            $component->assertSee($visibleDescription);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function settings_hub_toggles_completed_checklist(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $user = $this->createOwner();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(SettingsHub::class)
                ->assertSet('showCompletedChecklist', false)
                ->call('toggleCompletedChecklist')
                ->assertSet('showCompletedChecklist', true);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function critical_complete_does_not_require_full_recommended_checklist(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            // Org GLN (+ matching company prefix) before site create so GLN validation passes.
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0366159000010',
                'company_prefix' => '0366159',
            ]);
            $tenant->save();

            $site = Site::query()->create([
                'name' => 'Hub Critical '.Str::random(6),
                'gln' => self::SITE_GLN,
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $site->getKey();

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0366159000010',
                'company_prefix' => '0366159',
                'default_receive_site_id' => (int) $site->getKey(),
                'default_ship_from_site_id' => (int) $site->getKey(),
            ]);
            TenantSettings::forTenant($tenant)->setOutboundChoreographyDeferredAt(null);
            $tenant->save();
            // Refresh tenancy container so Livewire/tenant() sees updated settings.
            tenancy()->end();
            tenancy()->initialize($tenant->fresh());

            $onboarding = TenantOnboarding::forTenant(tenant());
            $this->assertTrue($onboarding->isComplete());
            $this->assertTrue($onboarding->isCriticalComplete());
            $this->assertSame(100, $onboarding->criticalScore());
            $this->assertLessThan(100, $onboarding->score());

            $user = $this->createOwner();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $component = Livewire::test(SettingsHub::class);

            $this->assertTrue($component->instance()->isReadinessComplete());
            $this->assertFalse($component->instance()->isRecommendedComplete());
            $this->assertSame(100, $component->instance()->criticalScore());
            $this->assertLessThan(100, $component->instance()->readinessScore());

            $component
                ->assertSee('Critical go-live ready')
                ->assertSee('Recommended setup still open')
                ->assertDontSee('Guided setup');

            $labels = collect($component->instance()->cardSections())
                ->flatMap(fn (array $section) => collect($section['cards'])->pluck('label'))
                ->all();
            $this->assertNotContains('Guided setup', $labels);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function settings_hub_can_defer_outbound_from_checklist(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            TenantSettings::forTenant($tenant)->setOutboundChoreographyDeferredAt(null);
            $this->deactivatedOutboundConnectionIds = OutboundConnection::query()
                ->where('is_active', true)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            if ($this->deactivatedOutboundConnectionIds !== []) {
                OutboundConnection::query()
                    ->whereIn('id', $this->deactivatedOutboundConnectionIds)
                    ->update(['is_active' => false]);
            }
            $tenant->save();

            $user = $this->createOwner();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $component = Livewire::test(SettingsHub::class);

            $outbound = collect($component->instance()->incompleteChecklistItems())
                ->first(fn (array $item): bool => ($item['id'] ?? null) === 'outbound_configured');

            $this->assertNotNull($outbound);
            $this->assertTrue($component->instance()->canDeferOutbound($outbound));

            $component
                ->assertSee('Defer outbound for now')
                ->call('acknowledgeOutboundDeferred');

            $this->assertNotNull(
                TenantSettings::forTenant($tenant->fresh())->outboundChoreographyDeferredAt(),
            );

            $byId = collect(TenantOnboarding::forTenant($tenant->fresh())->items())
                ->keyBy('id');
            $this->assertTrue($byId['outbound_configured']['done']);
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

        $settings = TenantSettings::forTenant($tenant);
        $this->priorProfile = $tenant->profile;
        $this->priorGln = $settings->gln();
        $this->priorCompanyPrefix = $settings->companyPrefix();
        $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        $this->priorOutboundDeferredAt = $settings->outboundChoreographyDeferredAt();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
                $this->siteIds = [];
            }

            if ($this->deactivatedOutboundConnectionIds !== []) {
                OutboundConnection::query()
                    ->whereIn('id', $this->deactivatedOutboundConnectionIds)
                    ->update(['is_active' => true]);
                $this->deactivatedOutboundConnectionIds = [];
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
            $settings->setOutboundChoreographyDeferredAt($this->priorOutboundDeferredAt);
            $restored->save();
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->priorProfile = null;
        $this->priorGln = null;
        $this->priorCompanyPrefix = null;
        $this->priorDefaultReceiveSiteId = null;
        $this->priorDefaultShipFromSiteId = null;
        $this->priorOutboundDeferredAt = null;
    }
}
