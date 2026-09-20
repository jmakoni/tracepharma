<?php

namespace Tests\Feature\MasterData;

use App\Domain\Gs1\CheckDigit;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Sites\Pages\CreateSite;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gs1IdentityStatus;
use App\Support\Gs1\SglnResolution;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteFormIdentityTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    #[Test]
    public function org_site_form_disables_derived_sgln_when_gln_is_under_gcp(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useCompanyPrefix($tenant, '0399991', '0399991000008');
            $this->actAsOwner();

            $gln = $this->glnUnderPrefix('0399991', str_pad((string) random_int(20000, 89999), 5, '0', STR_PAD_LEFT));
            $urn = SglnResolution::fromCompanyPrefix($gln, '0399991');
            $this->assertNotNull($urn);

            Livewire::test(CreateSite::class)
                ->fillForm([
                    'name' => 'Org warehouse '.uniqid(),
                    'gln' => $gln,
                ])
                ->assertFormFieldIsDisabled('sgln')
                ->assertSee($urn)
                ->assertSee('Derived from this GLN and your company prefix');
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function org_site_form_asks_for_a_paste_when_gln_is_not_under_gcp(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useCompanyPrefix($tenant, '0399991', '0399991000008');
            $this->actAsOwner();

            $gln = $this->glnUnderPrefix('555555', str_pad((string) random_int(200000, 899999), 6, '0', STR_PAD_LEFT));

            Livewire::test(CreateSite::class)
                ->fillForm([
                    'name' => 'Off-prefix warehouse '.uniqid(),
                    'gln' => $gln,
                ])
                ->assertFormFieldIsEnabled('sgln')
                ->assertSee(Gs1IdentityStatus::ORG_NOT_UNDER_PREFIX);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function partner_form_shows_missing_sgln_status_for_gln_only(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useCompanyPrefix($tenant, '0399991', '0399991000008');
            $this->actAsOwner();

            $gln = $this->glnUnderPrefix('0301160', str_pad((string) random_int(20000, 89999), 5, '0', STR_PAD_LEFT));
            $partner = TradingPartner::query()->create([
                'name' => 'Identity partner '.uniqid(),
                'gln' => $gln,
                'partner_type' => PartnerType::Wholesaler,
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $this->assertNull($partner->fresh()->sgln);

            Livewire::test(CreateSite::class)
                ->fillForm([
                    'trading_partner_id' => $partner->getKey(),
                    'name' => 'Partner dock '.uniqid(),
                    'gln' => $this->glnUnderPrefix('0301160', str_pad((string) random_int(20000, 89999), 5, '0', STR_PAD_LEFT)),
                ])
                ->assertSee(Gs1IdentityStatus::PARTNER_MISSING);
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function actAsOwner(): void
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create();
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

        if ($this->partnerIds !== []) {
            TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
            $this->partnerIds = [];
        }

        $current = $tenant->fresh() ?? $tenant;
        $current->forceFill([
            'gln' => $this->priorGln,
            'company_prefix' => $this->priorCompanyPrefix,
        ])->save();

        tenancy()->end();
    }
}
