<?php

namespace Tests\Feature\MasterData;

use App\Domain\Gs1\CheckDigit;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Gs1\Sgln;
use App\Support\Gs1\SglnResolution;
use App\Support\TenantSettings;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteSglnAutoGenerateTest extends TestCase
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
    public function org_site_create_stores_sgln_from_gln_under_org_prefix(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $prefix = '0399991';
            $this->useCompanyPrefix($tenant, $prefix, '0399991000008');
            $gln = $this->glnUnderPrefix($prefix, str_pad((string) random_int(20000, 89999), 5, '0', STR_PAD_LEFT));

            $site = $this->createOrgSite($gln);

            $this->assertSame(Sgln::toUrn($gln, strlen($prefix)), $site->fresh()->sgln);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function org_site_create_stores_sgln_from_a_sibling_facility_prefix(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useCompanyPrefix($tenant, '0399991', '0399991000008');

            $locationA = str_pad((string) random_int(20000, 89999), 5, '0', STR_PAD_LEFT);
            $locationB = str_pad((string) random_int(20000, 89999), 5, '0', STR_PAD_LEFT);
            if ($locationA === $locationB) {
                $locationB = str_pad((string) (((int) $locationA + 1) % 100000), 5, '0', STR_PAD_LEFT);
            }
            $siblingGln = $this->glnUnderPrefix('8765432', $locationA);
            $warehouseGln = $this->glnUnderPrefix('8765432', $locationB);

            $this->createOrgSite($siblingGln, 'urn:epc:id:sgln:8765432.'.$locationA.'.0');
            $warehouse = $this->createOrgSite($warehouseGln);

            $this->assertSame(Sgln::toUrn($warehouseGln, 7), $warehouse->fresh()->sgln);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function org_site_create_uses_org_prefix_length_when_no_prefix_covers_the_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $orgPrefix = '0399991';
            $this->useCompanyPrefix($tenant, $orgPrefix, '0399991000008');
            $gln = $this->glnUnderPrefix('555555', str_pad((string) random_int(200000, 899999), 6, '0', STR_PAD_LEFT));

            $site = $this->createOrgSite($gln);

            $this->assertSame(
                SglnResolution::fromPrefixLength($gln, $orgPrefix),
                $site->fresh()->sgln,
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function partner_site_with_gln_only_does_not_guess_an_sgln(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useCompanyPrefix($tenant, '0399991', '0399991000008');

            $partner = TradingPartner::query()->create([
                'name' => 'Sgln autogen partner '.uniqid(),
                'gln' => $this->glnUnderPrefix('0301160', str_pad((string) random_int(20000, 89999), 5, '0', STR_PAD_LEFT)),
                'partner_type' => PartnerType::Wholesaler,
                'is_active' => true,
            ]);
            $this->partnerIds[] = (int) $partner->getKey();

            $site = Site::query()->create([
                'name' => 'Partner dock '.uniqid(),
                'gln' => $this->glnUnderPrefix('0301160', str_pad((string) random_int(20000, 89999), 5, '0', STR_PAD_LEFT)),
                'trading_partner_id' => $partner->getKey(),
                'is_organization_facility' => false,
                'is_active' => true,
            ]);
            $this->siteIds[] = (int) $site->getKey();

            $this->assertNull($site->fresh()->sgln);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function org_site_gln_change_updates_sgln_and_keeps_a_matching_typed_urn(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $prefix = '0399991';
            $this->useCompanyPrefix($tenant, $prefix, '0399991000008');
            $gln = $this->glnUnderPrefix($prefix, str_pad((string) random_int(20000, 39999), 5, '0', STR_PAD_LEFT));
            $typed = Sgln::toUrn($gln, strlen($prefix), '4');
            $site = $this->createOrgSite($gln, $typed);

            $this->assertSame($typed, $site->fresh()->sgln);

            $nextGln = $this->glnUnderPrefix($prefix, str_pad((string) random_int(40000, 89999), 5, '0', STR_PAD_LEFT));
            $site->forceFill(['gln' => $nextGln])->save();

            $this->assertSame(Sgln::toUrn($nextGln, strlen($prefix)), $site->fresh()->sgln);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function org_site_with_invalid_gln_check_digit_does_not_store_mismatched_sgln(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->useCompanyPrefix($tenant, '0399991', '0399991000008');
            $wrongGln = '0366159000123'; // body 036615900012 has check digit 5

            $site = $this->createOrgSite($wrongGln, 'urn:epc:id:sgln:036615.900012.0');

            $this->assertNull($site->fresh()->sgln);
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function createOrgSite(string $gln, ?string $sgln = null): Site
    {
        $site = Site::query()->create(array_filter([
            'name' => 'Sgln autogen '.uniqid(),
            'gln' => $gln,
            'sgln' => $sgln,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
            'is_active' => true,
        ], static fn ($value): bool => $value !== null));
        $this->siteIds[] = (int) $site->getKey();

        return $site;
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

        $this->priorGln = null;
        $this->priorCompanyPrefix = null;
    }
}
