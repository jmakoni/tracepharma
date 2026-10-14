<?php

namespace Tests\Feature\MasterData;

use App\Domain\Gs1\CheckDigit;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Sites\Pages\ViewSite;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Fda\AddressFingerprint;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Site view (including the compact ATP Licenses tab) must show FEI and other
 * identity identifiers that already exist on Edit.
 */
class SiteViewIdentityIdentifiersTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $establishmentIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    #[Test]
    public function compact_atp_tab_shows_fei_and_identity_identifiers(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useCompanyPrefix($tenant, '0399991', '0399991000008');
            $this->actAsOwner();

            $suffix = Str::lower(Str::random(6));
            $fei = '9'.str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT);
            $dea = 'BD'.strtoupper(Str::random(7));
            $duns = (string) random_int(100000000, 999999999);
            $hin = 'HIN'.strtoupper(Str::random(6));
            $chemical = 'CHEM'.strtoupper(Str::random(4));
            $street = '8812 Compact Profile Ave '.$suffix;
            $gln = $this->glnUnderPrefix('0399991', str_pad((string) random_int(20000, 89999), 5, '0', STR_PAD_LEFT));

            $org = FdaOrganization::query()->create([
                'original_name' => 'SSOR Site View Org '.$suffix,
                'canonical_name' => CompanyNameNormalizer::canonical('SSOR Site View Org '.$suffix),
                'name' => 'SSOR Site View Org '.$suffix,
                'partner_type' => PartnerType::Wholesaler,
                'street_address' => '100 View St',
                'city' => 'Austin',
                'state_province' => 'TX',
                'postal_code' => '78701',
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->orgIds[] = (int) $org->id;

            $establishment = FdaEstablishment::query()->create([
                'fda_organization_id' => $org->id,
                'fei_number' => $fei,
                'name' => 'SSOR Site View Plant '.$suffix,
                'firm_name' => 'SSOR Site View Plant '.$suffix,
                'street_address' => $street,
                'city' => 'Austin',
                'state_province' => 'TX',
                'postal_code' => '78701',
                'country_code' => 'US',
                'address_fingerprint' => AddressFingerprint::make($street, 'Austin', 'TX', '78701', 'US'),
                'is_active' => true,
            ]);
            $this->establishmentIds[] = (int) $establishment->id;

            $site = Site::query()->create([
                'fda_establishment_id' => $establishment->id,
                'name' => 'SSOR Site View Dock '.$suffix,
                'code' => 'SV-'.$suffix,
                'gln' => $gln,
                'dea_number' => $dea,
                'duns_number' => $duns,
                'hin_number' => $hin,
                'chemical_reg_number' => $chemical,
                'street_address' => $street,
                'city' => 'Austin',
                'state' => 'TX',
                'zipcode' => '78701',
                'country_code' => 'US',
                'is_organization_facility' => true,
                'is_active' => true,
            ]);
            $this->siteIds[] = (int) $site->id;

            $site = $site->fresh();
            $this->assertNotNull($site);
            $this->assertNotNull($site->sgln);

            Livewire::test(ViewSite::class, [
                'record' => $site->getKey(),
                'activeRelationManager' => '1',
            ])
                ->assertSee('FEI', false)
                ->assertSee($fei, false)
                ->assertSee('DEA', false)
                ->assertSee($dea, false)
                ->assertSee('DUNS', false)
                ->assertSee($duns, false)
                ->assertSee('HIN', false)
                ->assertSee($hin, false)
                ->assertSee('Chemical Reg', false)
                ->assertSee($chemical, false)
                ->assertSee('SGLN', false)
                ->assertSee($site->sgln, false)
                ->assertSee('GLN', false)
                ->assertSee($gln, false)
                ->assertSee('shadow-xl', false)
                ->assertSee('ATP Readiness', false)
                ->assertSee('About this check', false)
                ->assertDontSee('Expired (relevant)', false)
                ->assertSee('Physical address', false)
                ->assertSee(\App\Support\Catalog\DisplayName::clean($street), false);
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function actAsOwner(): void
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create();
        $this->userIds[] = (int) $user->id;
        $user->assignRole(TenantRole::Owner->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    private function glnUnderPrefix(string $prefix, string $locationRef): string
    {
        $body12 = $prefix.$locationRef;
        $this->assertSame(12, strlen($body12));

        return $body12.CheckDigit::mod10($body12);
    }

    private function useCompanyPrefix(Tenant $tenant, string $prefix, string $gln): void
    {
        TenantSettings::forTenant($tenant)
            ->setGln($gln)
            ->setCompanyPrefix($prefix);
        $tenant->save();

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
        $this->priorGln = $settings->gln();
        $this->priorCompanyPrefix = $settings->companyPrefix();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            tenancy()->initialize($tenant->fresh() ?? $tenant);
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        $current = $tenant->fresh() ?? $tenant;
        $current->forceFill([
            'gln' => $this->priorGln,
            'company_prefix' => $this->priorCompanyPrefix,
        ])->save();

        tenancy()->end();

        if ($this->establishmentIds !== []) {
            FdaEstablishment::query()->whereIn('id', $this->establishmentIds)->delete();
            $this->establishmentIds = [];
        }

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
            $this->orgIds = [];
        }
    }
}
