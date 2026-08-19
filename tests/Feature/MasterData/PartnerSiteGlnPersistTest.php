<?php

namespace Tests\Feature\MasterData;

use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Sites\Pages\ViewSite;
use App\Filament\App\Resources\TradingPartners\Pages\ViewTradingPartner;
use App\Filament\App\Resources\TradingPartners\RelationManagers\SitesRelationManager;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWddFacility;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Fda\AddressFingerprint;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\Gs1\Gtin;
use App\Support\MasterData\PartnerSiteCreate;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Partner site GLN must persist from the trading-partner Sites tab and the site page.
 *
 * LA Smile stage: Xttrium site 3 is stamped to an FDA WDD row whose GLN is null.
 * A typed site GLN still has to survive create and edit.
 */
class PartnerSiteGlnPersistTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const GLN_PREFIX = '094229';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $facilityIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function relation_manager_edit_persists_a_new_site_gln(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();
            $partner = $this->createPartner();
            $site = $this->createSiteFor($partner, $this->uniqueGln('20'));
            $nextGln = $this->uniqueGln('21');

            Livewire::test(SitesRelationManager::class, [
                'ownerRecord' => $partner,
                'pageClass' => ViewTradingPartner::class,
            ])
                ->mountAction(TestAction::make('edit')->table($site))
                ->fillForm(['gln' => $nextGln])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $this->assertSame($nextGln, $site->fresh()->gln);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function view_site_edit_keeps_typed_gln_when_the_linked_wdd_is_re_picked(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();
            [$partner, $site] = $this->createFdaLinkedSiteWithNullFacilityGln();
            $facilityId = $site->fda_wdd_facility_id;
            $this->assertNotNull($facilityId);
            $nextGln = $this->uniqueGln('33');

            Livewire::test(ViewSite::class, ['record' => $site->getKey()])
                ->mountAction('edit')
                ->fillForm(['gln' => $nextGln])
                ->fillForm(['_fda_pick_fda_wdd_facility_id' => (string) $facilityId])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $this->assertSame($nextGln, $site->fresh()->gln);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function view_site_edit_persists_a_new_site_gln_when_fda_wdd_has_no_gln(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();
            [$partner, $site] = $this->createFdaLinkedSiteWithNullFacilityGln();
            $nextGln = $this->uniqueGln('31');

            Livewire::test(ViewSite::class, ['record' => $site->getKey()])
                ->mountAction('edit')
                ->fillForm(['gln' => $nextGln])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $this->assertSame($nextGln, $site->fresh()->gln);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function view_site_edit_persists_site_gln_through_password_confirm(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();
            config(['tracepharma.regulatory_compliance.password_gate' => true]);

            [$partner, $site] = $this->createFdaLinkedSiteWithNullFacilityGln();
            $nextGln = $this->uniqueGln('32');

            Livewire::test(ViewSite::class, ['record' => $site->getKey()])
                ->mountAction('edit')
                ->fillForm(['gln' => $nextGln])
                ->mountAction('submit')
                ->fillForm(['regulatory_password' => 'password'])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $this->assertSame($nextGln, $site->fresh()->gln);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function manual_create_persists_a_typed_site_gln(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();
            $partner = $this->createPartner();
            $gln = $this->uniqueGln('40');
            $name = 'Typed Gln Site '.Str::lower(Str::random(6));

            Livewire::test(SitesRelationManager::class, [
                'ownerRecord' => $partner,
                'pageClass' => ViewTradingPartner::class,
            ])
                ->mountAction(TestAction::make('create')->table())
                ->fillForm([
                    'create_mode' => PartnerSiteCreate::MODE_MANUAL,
                    'name' => $name,
                    'gln' => $gln,
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $site = Site::query()
                ->where('trading_partner_id', $partner->id)
                ->where('name', $name)
                ->first();
            $this->assertNotNull($site);
            $this->siteIds[] = (int) $site->id;
            $this->assertSame($gln, $site->gln);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function fda_create_keeps_a_typed_gln_when_the_registry_row_has_none(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actAsOwner();
            [$org, $facility] = $this->createFdaOrgAndFacility(facilityGln: null);
            $partner = $this->createPartner($org);
            $typedGln = $this->uniqueGln('50');
            $name = 'Fda Null Gln Site '.Str::lower(Str::random(6));

            Livewire::test(SitesRelationManager::class, [
                'ownerRecord' => $partner,
                'pageClass' => ViewTradingPartner::class,
            ])
                ->mountAction(TestAction::make('create')->table())
                ->fillForm([
                    'create_mode' => PartnerSiteCreate::MODE_FDA,
                    '_fda_pick_partner_location' => 'wdd:'.$facility->id,
                    'name' => $name,
                    'gln' => $typedGln,
                ])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $site = Site::query()
                ->where('trading_partner_id', $partner->id)
                ->where('fda_wdd_facility_id', $facility->id)
                ->first();
            $this->assertNotNull($site);
            $this->siteIds[] = (int) $site->id;
            $this->assertSame($typedGln, $site->gln);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{0: TradingPartner, 1: Site}
     */
    private function createFdaLinkedSiteWithNullFacilityGln(): array
    {
        [$org, $facility] = $this->createFdaOrgAndFacility(facilityGln: null);
        $partner = $this->createPartner($org);
        $site = $this->createSiteFor($partner, $this->uniqueGln('30'), [
            'fda_wdd_facility_id' => $facility->id,
            'name' => $facility->name,
        ]);

        return [$partner, $site];
    }

    /**
     * @return array{0: FdaOrganization, 1: FdaWddFacility}
     */
    private function createFdaOrgAndFacility(?string $facilityGln): array
    {
        $suffix = Str::lower(Str::random(6));
        $name = 'SSOR Gln Persist Org '.$suffix;
        $org = FdaOrganization::query()->create([
            'original_name' => $name,
            'canonical_name' => CompanyNameNormalizer::canonical($name),
            'name' => $name,
            'partner_type' => PartnerType::Manufacturer,
            'street_address' => '100 '.$name,
            'city' => 'OrgCity',
            'state_province' => 'TX',
            'postal_code' => '78701',
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $org->id;

        $facilityName = 'SSOR Gln Persist Hub '.$suffix;
        $street = '11 Wddst '.$suffix;
        $city = 'HubCity'.$suffix;
        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'name' => $facilityName,
            'facility_name' => $facilityName,
            'gln' => $facilityGln,
            'street_address' => $street,
            'city' => $city,
            'state_province' => 'TX',
            'postal_code' => '78701',
            'country_code' => 'US',
            'full_address' => $street.', '.$city.', TX',
            'address_fingerprint' => AddressFingerprint::make($street, $city, 'TX', '78701', 'US'),
            'is_active' => true,
        ]);
        $this->facilityIds[] = (int) $facility->id;

        return [$org, $facility];
    }

    private function createPartner(?FdaOrganization $org = null): TradingPartner
    {
        $partner = TradingPartner::query()->create([
            'fda_organization_id' => $org?->id,
            'name' => $org?->name ?? 'Gln Persist Partner '.Str::lower(Str::random(6)),
            'gln' => $this->uniqueGln('10'),
            'partner_type' => PartnerType::Manufacturer,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->partnerIds[] = (int) $partner->id;

        return $partner;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSiteFor(TradingPartner $partner, string $gln, array $overrides = []): Site
    {
        $site = Site::query()->create(array_merge([
            'trading_partner_id' => $partner->id,
            'name' => 'Gln Persist Site '.Str::lower(Str::random(6)),
            'gln' => $gln,
            'is_organization_facility' => false,
            'is_active' => true,
        ], $overrides));
        $this->siteIds[] = (int) $site->id;

        return $site;
    }

    private function actAsOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
        $this->userIds[] = (int) $user->id;
        $user->syncRoles([TenantRole::Owner->value]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return $user;
    }

    private function uniqueGln(string $marker): string
    {
        do {
            $body = self::GLN_PREFIX.$marker.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists()
            || TradingPartner::query()->where('gln', $gln)->exists());

        return $gln;
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

        return $tenant;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
            }
            if ($this->partnerIds !== []) {
                Site::query()->whereIn('trading_partner_id', $this->partnerIds)->delete();
                TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
            }
            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
            }
        }

        if ($this->facilityIds !== []) {
            FdaWddFacility::query()->whereIn('id', $this->facilityIds)->delete();
        }
        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }

        $this->siteIds = [];
        $this->partnerIds = [];
        $this->userIds = [];
        $this->facilityIds = [];
        $this->orgIds = [];

        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
