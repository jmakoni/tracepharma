<?php

namespace Tests\Feature\Shipping;

use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Gs1\Gtin;
use App\Support\Shipping\ResolveOwningPartySite;
use App\Support\TenantSettings;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The owning party on an outbound shipment is us, never a supplier.
 *
 * GLNs are prefixed 094411 so rows are traceable in the shared demo2 tenant.
 */
class ResolveOwningPartySiteTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const GLN_PREFIX = '094411';

    private static bool $demo2TenantReady = false;

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    #[Test]
    public function it_prefers_the_organization_facility_holding_the_organization_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $organizationGln = $this->uniqueGln('10');
            $shipFromGln = $this->uniqueGln('11');

            $corporate = $this->createOrganizationFacility($organizationGln, 'Owning Party Corporate 094411');
            $shipFrom = $this->createOrganizationFacility($shipFromGln, 'Owning Party Dock 094411');
            $this->useOrganizationGln($tenant, $organizationGln);

            $resolved = app(ResolveOwningPartySite::class)->handle($shipFrom);

            $this->assertTrue($corporate->is($resolved));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_never_picks_a_partner_owned_site_carrying_the_organization_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $organizationGln = $this->uniqueGln('20');
            $shipFromGln = $this->uniqueGln('21');

            $partner = TradingPartner::query()->create([
                'name' => 'Owning Party Supplier 094411',
                'gln' => $this->uniqueGln('22'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);

            // Inbound EPCIS master data mirrored our own GLN onto a supplier's site.
            $mirrored = Site::query()->create([
                'trading_partner_id' => $partner->getKey(),
                'name' => 'Owning Party Mirrored 094411',
                'gln' => $organizationGln,
                'is_active' => true,
                'is_organization_facility' => false,
                'country_code' => 'US',
            ]);

            $shipFrom = $this->createOrganizationFacility($shipFromGln, 'Owning Party Dock 094411');
            $this->useOrganizationGln($tenant, $organizationGln);

            $resolved = app(ResolveOwningPartySite::class)->handle($shipFrom);

            $this->assertFalse($mirrored->is($resolved));
            $this->assertNull($resolved->trading_partner_id);
            $this->assertTrue((bool) $resolved->is_organization_facility);
            $this->assertTrue((bool) $resolved->is_active);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function it_falls_back_to_an_organization_facility_when_the_organization_gln_names_no_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $shipFromGln = $this->uniqueGln('31');
            $shipFrom = $this->createOrganizationFacility($shipFromGln, 'Owning Party Dock 094411');
            $this->useOrganizationGln($tenant, $this->uniqueGln('30'));

            $resolved = app(ResolveOwningPartySite::class)->handle($shipFrom);

            $this->assertNull($resolved->trading_partner_id);
            $this->assertTrue((bool) $resolved->is_organization_facility);
            $this->assertNotEmpty($resolved->gln);
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function createOrganizationFacility(string $gln, string $name): Site
    {
        return Site::query()->create([
            'name' => $name,
            'gln' => $gln,
            'is_active' => true,
            'is_organization_facility' => true,
            'country_code' => 'US',
        ]);
    }

    private function useOrganizationGln(Tenant $tenant, string $gln): Tenant
    {
        TenantSettings::forTenant($tenant)
            ->setGln($gln)
            ->setCompanyPrefix(substr($gln, 0, 7));
        $tenant->save();

        tenancy()->end();
        $fresh = $tenant->fresh();
        tenancy()->initialize($fresh);

        return $fresh;
    }

    private function uniqueGln(string $marker): string
    {
        $body12 = self::GLN_PREFIX.$marker.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        return $body12.Gtin::checkDigit($body12);
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
        if (tenancy()->initialized) {
            Site::query()->where('gln', 'like', self::GLN_PREFIX.'%')->delete();
            TradingPartner::query()->where('gln', 'like', self::GLN_PREFIX.'%')->delete();

            $current = $tenant->fresh() ?? $tenant;
            TenantSettings::forTenant($current)
                ->setGln($this->priorGln)
                ->setCompanyPrefix($this->priorCompanyPrefix);
            $current->save();

            tenancy()->end();
        }

        $this->priorGln = null;
        $this->priorCompanyPrefix = null;
    }
}
