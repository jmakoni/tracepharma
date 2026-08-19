<?php

namespace Tests\Feature\Epcis;

use App\Domain\Gs1\CheckDigit;
use App\Enums\TenantProfile;
use App\Models\Site;
use App\Models\Tenant;
use App\Support\Epcis\ResolveSiteLocationGlns;
use App\Support\Gs1\Sgln;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveSiteLocationGlnsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    #[Test]
    public function it_encodes_an_org_warehouse_gln_using_a_sibling_site_sgln_prefix(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $locationA = str_pad((string) random_int(20000, 89999), 5, '0', STR_PAD_LEFT);
            $locationB = str_pad((string) random_int(20000, 89999), 5, '0', STR_PAD_LEFT);
            if ($locationA === $locationB) {
                $locationB = str_pad((string) (((int) $locationA + 1) % 100000), 5, '0', STR_PAD_LEFT);
            }

            $siblingGln = $this->glnUnderPrefix('8765432', $locationA);
            $warehouseGln = $this->glnUnderPrefix('8765432', $locationB);
            $this->assertNotSame($siblingGln, $warehouseGln);

            $sibling = Site::query()->create([
                'name' => 'Sibling HQ '.uniqid(),
                'gln' => $siblingGln,
                'sgln' => 'urn:epc:id:sgln:8765432.'.$locationA.'.0',
                'is_active' => true,
                'trading_partner_id' => null,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $sibling->getKey();

            $warehouse = Site::query()->create([
                'name' => 'Minesota - Warehouse fixture',
                'gln' => $warehouseGln,
                'sgln' => null,
                'is_active' => true,
                'trading_partner_id' => null,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $warehouse->getKey();

            $resolved = app(ResolveSiteLocationGlns::class)->handle(
                (int) $warehouse->getKey(),
                'Transfer destination site',
            );

            $this->assertSame($warehouseGln, $resolved['gln']);
            $this->assertSame(
                Sgln::toUrn($warehouseGln, 7),
                $resolved['sgln_urn'],
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_authors_an_org_warehouse_after_save_fills_sgln_from_gln(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $gln = $this->glnUnderPrefix('555555', '000001');
            $site = Site::query()->create([
                'name' => 'Length-fallback warehouse',
                'gln' => $gln,
                'is_active' => true,
                'trading_partner_id' => null,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $site->getKey();

            $this->assertNotNull($site->fresh()->sgln);

            $resolved = app(ResolveSiteLocationGlns::class)->handle(
                (int) $site->getKey(),
                'Transfer destination site',
            );

            $this->assertSame($gln, $resolved['gln']);
            $this->assertSame($site->fresh()->sgln, $resolved['sgln_urn']);
        } finally {
            $this->cleanup();
        }
    }

    private function glnUnderPrefix(string $prefix, string $locationRef): string
    {
        $body12 = $prefix.$locationRef;
        $this->assertSame(12, strlen($body12));

        return $body12.CheckDigit::mod10($body12);
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

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        tenancy()->end();
    }
}
