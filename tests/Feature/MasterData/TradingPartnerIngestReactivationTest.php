<?php

namespace Tests\Feature\MasterData;

use App\Actions\Epcis\EnsureCatalogPartiesFromEpcisLocations;
use App\Actions\MasterData\EnsureManufacturerPartnerFromCatalog;
use App\Actions\MasterData\EnsureWholesalerPartnerFromCatalog;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Fda\FdaOrganization;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Gs1\Gtin;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ingest may enrich a tenant partner it matched by GLN, but it must never undo a
 * deliberate deactivation or reclassify a partner the tenant already typed.
 */
class TradingPartnerIngestReactivationTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<string> */
    private array $cleanupGlns = [];

    /** @var list<int> */
    private array $orgIds = [];

    #[Test]
    public function manufacturer_ingest_keeps_a_deactivated_partner_inactive_and_typed(): void
    {
        $gln = $this->uniqueTestGln('71');
        $this->cleanupGlns = [$gln];

        $org = $this->createFdaOrganization($gln, PartnerType::Manufacturer);

        $this->initializeDemo2Tenant();

        try {
            $partner = $this->createDeactivatedPartner($gln, PartnerType::Wholesaler);

            $resolved = app(EnsureManufacturerPartnerFromCatalog::class)->handle($org);

            $this->assertSame((int) $partner->getKey(), (int) $resolved->getKey());
            $this->assertFalse((bool) $resolved->is_active, 'Ingest must not reactivate a deactivated partner.');
            $this->assertSame(PartnerType::Wholesaler, $resolved->partner_type, 'Ingest must not reclassify a typed partner.');
            $this->assertSame((int) $org->getKey(), (int) $resolved->fda_organization_id);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function wholesaler_ingest_keeps_a_deactivated_partner_inactive_and_typed(): void
    {
        $gln = $this->uniqueTestGln('72');
        $this->cleanupGlns = [$gln];

        $org = $this->createFdaOrganization($gln, PartnerType::Wholesaler);

        $this->initializeDemo2Tenant();

        try {
            $partner = $this->createDeactivatedPartner($gln, PartnerType::Logistics3pl);

            $resolved = app(EnsureWholesalerPartnerFromCatalog::class)->handle($org);

            $this->assertSame((int) $partner->getKey(), (int) $resolved->getKey());
            $this->assertFalse((bool) $resolved->is_active);
            $this->assertSame(PartnerType::Logistics3pl, $resolved->partner_type);
            $this->assertSame((int) $org->getKey(), (int) $resolved->fda_organization_id);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function ingest_classifies_a_partner_still_marked_other(): void
    {
        $gln = $this->uniqueTestGln('73');
        $this->cleanupGlns = [$gln];

        $org = $this->createFdaOrganization($gln, PartnerType::Manufacturer);

        $this->initializeDemo2Tenant();

        try {
            $partner = $this->createDeactivatedPartner($gln, PartnerType::Other);

            $resolved = app(EnsureManufacturerPartnerFromCatalog::class)->handle($org);

            $this->assertSame((int) $partner->getKey(), (int) $resolved->getKey());
            $this->assertSame(PartnerType::Manufacturer, $resolved->partner_type);
            $this->assertFalse((bool) $resolved->is_active);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function epcis_location_ingest_keeps_a_deactivated_partner_inactive(): void
    {
        $gln = $this->uniqueTestGln('74');
        $this->cleanupGlns = [$gln];

        $org = $this->createFdaOrganization($gln, PartnerType::Pharmacy);

        $tenant = $this->initializeDemo2Tenant();
        $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
        tenancy()->initialize($tenant->fresh());

        try {
            $partner = $this->createDeactivatedPartner($gln, PartnerType::Pharmacy);

            app(EnsureCatalogPartiesFromEpcisLocations::class)->handle(
                [[
                    'gln' => $gln,
                    'name' => 'Ingest Dest Co '.uniqid(),
                    'country_code' => 'US',
                ]],
                ['destination_owning_party_gln' => $gln],
            );

            $resolved = $partner->fresh();

            $this->assertNotNull($resolved);
            $this->assertFalse((bool) $resolved->is_active, 'EPCIS location ingest must not reactivate a deactivated partner.');
            $this->assertSame(PartnerType::Pharmacy, $resolved->partner_type);
            $this->assertSame(
                (int) $org->getKey(),
                (int) $resolved->fda_organization_id,
                'Ingest should still link the partner to the FDA organization it matched.',
            );
        } finally {
            $this->cleanup();
        }
    }

    private function createFdaOrganization(string $gln, PartnerType $type): FdaOrganization
    {
        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR CUT Ingest '.uniqid(),
            'canonical_name' => 'SSOR CUT INGEST '.uniqid(),
            'name' => 'SSOR CUT Ingest '.uniqid(),
            'partner_type' => $type,
            'gln' => $gln,
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $org->getKey();

        return $org;
    }

    private function createDeactivatedPartner(string $gln, PartnerType $type): TradingPartner
    {
        return TradingPartner::query()->create([
            'name' => 'Deactivated Partner '.uniqid(),
            'gln' => $gln,
            'partner_type' => $type,
            'country_code' => 'US',
            'is_active' => false,
        ]);
    }

    private function uniqueTestGln(string $prefix2): string
    {
        do {
            $body12 = $prefix2.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $gln = $body12.Gtin::checkDigit($body12);
        } while (FdaOrganization::query()->where('gln', $gln)->exists());

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

        return $tenant;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            foreach ($this->cleanupGlns as $gln) {
                $partnerIds = TradingPartner::query()->where('gln', $gln)->pluck('id')->all();

                Site::query()->where('gln', $gln)->delete();

                if ($partnerIds !== []) {
                    DB::table('sites')->whereIn('trading_partner_id', $partnerIds)->delete();
                    TradingPartner::query()->whereIn('id', $partnerIds)->delete();
                }
            }

            tenancy()->end();
        }

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
            $this->orgIds = [];
        }

        Tenant::query()
            ->whereKey(self::DEMO2_TENANT_ID)
            ->update(['profile' => TenantProfile::Pharmacy->value]);

        $this->cleanupGlns = [];
    }
}
