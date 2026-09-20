<?php

namespace Tests\Unit\Support\Shipping;

use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Principal;
use App\Models\Site;
use App\Models\Tenant;
use App\Support\Shipping\OnHandLotRollup;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PlacesEpcOnHandAtSite;
use Tests\TestCase;

class OnHandLotRollupTest extends TestCase
{
    use PlacesEpcOnHandAtSite;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $principalIds = [];

    #[Test]
    public function groups_sgtins_by_gtin_and_lot_with_pack_counts(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = $this->makeSite();
            $a1 = $this->makeOnHandSgtin($site, '03011633333331', 'LOT-A', now()->addDays(30)->toDateString());
            $a2 = $this->makeOnHandSgtin($site, '03011633333331', 'LOT-A', now()->addDays(40)->toDateString());
            $b1 = $this->makeOnHandSgtin($site, '03011633333331', 'LOT-B', now()->addDays(50)->toDateString());
            $otherGtin = $this->makeOnHandSgtin($site, '03011634444442', 'LOT-A', now()->addDays(60)->toDateString());

            $rows = app(OnHandLotRollup::class)->rows((int) $site->getKey());

            $lotA = $rows->first(
                fn (array $row): bool => $row['gtin14'] === '03011633333331' && $row['lot_number'] === 'LOT-A'
            );
            $this->assertNotNull($lotA);
            $this->assertSame(2, $lotA['total']);
            $this->assertSame(2, $lotA['pickable']);
            $this->assertSame(0, $lotA['hold_count']);
            $this->assertSame(2, $lotA['sgtin_count']);
            $this->assertSame(0, $lotA['sscc_count']);
            $this->assertSame(now()->addDays(30)->toDateString(), $lotA['min_expiry']);
            $this->assertSame(now()->addDays(40)->toDateString(), $lotA['max_expiry']);

            $lotB = $rows->first(
                fn (array $row): bool => $row['gtin14'] === '03011633333331' && $row['lot_number'] === 'LOT-B'
            );
            $this->assertNotNull($lotB);
            $this->assertSame(1, $lotB['total']);

            $other = $rows->first(
                fn (array $row): bool => $row['gtin14'] === '03011634444442' && $row['lot_number'] === 'LOT-A'
            );
            $this->assertNotNull($other);
            $this->assertSame(1, $other['total']);

            $this->assertGreaterThanOrEqual(3, $rows->count());
            unset($a1, $a2, $b1, $otherGtin);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function buckets_sscc_without_gtin_as_containers(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = $this->makeSite();
            $this->makeOnHandSscc($site);
            $this->makeOnHandSscc($site);

            $rows = app(OnHandLotRollup::class)->rows((int) $site->getKey());
            $containers = $rows->first(
                fn (array $row): bool => $row['gtin14'] === OnHandLotRollup::SSCC_PRODUCT_KEY
            );
            $this->assertNotNull($containers);
            $this->assertSame(2, $containers['total']);
            $this->assertSame(0, $containers['sgtin_count']);
            $this->assertSame(2, $containers['sscc_count']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function returns_empty_for_invalid_site(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $rows = app(OnHandLotRollup::class)->rows(0);
            $this->assertTrue($rows->isEmpty());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function filters_by_principal_when_provided(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = $this->makeSite();
            $principal = Principal::query()->create([
                'name' => 'Rollup Principal '.substr((string) str()->uuid(), 0, 8),
                'is_active' => true,
            ]);
            $this->principalIds[] = (int) $principal->getKey();

            $owned = $this->makeOnHandSgtin($site, '03011635555553', 'P-LOT', now()->addDays(20)->toDateString());
            $owned['epc']->forceFill(['principal_id' => $principal->getKey()])->save();

            $other = $this->makeOnHandSgtin($site, '03011635555553', 'P-LOT', now()->addDays(25)->toDateString());

            $filtered = app(OnHandLotRollup::class)->rows((int) $site->getKey(), (int) $principal->getKey());
            $row = $filtered->first(
                fn (array $r): bool => $r['gtin14'] === '03011635555553' && $r['lot_number'] === 'P-LOT'
            );
            $this->assertNotNull($row);
            $this->assertSame(1, $row['total']);

            $all = app(OnHandLotRollup::class)->rows((int) $site->getKey());
            $allRow = $all->first(
                fn (array $r): bool => $r['gtin14'] === '03011635555553' && $r['lot_number'] === 'P-LOT'
            );
            $this->assertNotNull($allRow);
            $this->assertSame(2, $allRow['total']);
            unset($other);
        } finally {
            $this->cleanup();
        }
    }

    private function makeSite(): Site
    {
        $gln = '03'.str_pad((string) random_int(0, 99_999_999_999), 11, '0', STR_PAD_LEFT);
        $site = Site::query()->create([
            'name' => 'Rollup Site '.substr((string) str()->uuid(), 0, 8),
            'gln' => $gln,
            'is_active' => true,
            'is_headquarters' => false,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        return $site;
    }

    /**
     * @return array{epc: Epc, serial: string}
     */
    private function makeOnHandSgtin(Site $site, string $gtin14, string $lot, string $expiry): array
    {
        $serial = 'RL'.random_int(10_000_000, 99_999_999);
        // Build URI from known gtin pieces: company 030116, indicator+item from gtin
        $indicatorAndItem = substr($gtin14, 1, 12); // skip leading 0 of GTIN-14 when company is 030116...
        // Simpler: use fromUri with matching gtin14 after save via forceFill
        $epc = Epc::fromUri('urn:epc:id:sgtin:030116.3'.substr((string) random_int(100000, 999999), 0, 6).'.'.$serial);
        $epc->first_seen_at = now();
        $epc->gtin14 = $gtin14;
        $epc->save();
        $this->epcIds[] = (int) $epc->getKey();

        EpcIlmd::query()->create([
            'epc_id' => $epc->getKey(),
            'gtin14' => $gtin14,
            'lot_number' => $lot,
            'expiry_date' => $expiry,
        ]);

        $placed = $this->placeEpcOnHandAtSite($site, $epc);
        $this->documentIds[] = (int) $placed['document']->getKey();
        $this->eventIds[] = (int) $placed['event']->getKey();

        return ['epc' => $epc->fresh(), 'serial' => $serial];
    }

    private function makeOnHandSscc(Site $site): Epc
    {
        // companyPrefix 030116 (6) + serialRef 11 chars (extension + 10) = GS1 SSCC serial body
        $serialRef = '0'.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        $epc = Epc::fromUri('urn:epc:id:sscc:030116.'.$serialRef);
        $this->assertSame('sscc', $epc->epc_type);
        $epc->first_seen_at = now();
        $epc->save();
        $this->epcIds[] = (int) $epc->getKey();

        $placed = $this->placeEpcOnHandAtSite($site, $epc);
        $this->documentIds[] = (int) $placed['document']->getKey();
        $this->eventIds[] = (int) $placed['event']->getKey();

        return $epc->fresh();
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

        foreach ($this->eventIds as $id) {
            DB::table('event_epcs')->where('event_id', $id)->delete();
            EpcisEvent::query()->whereKey($id)->delete();
        }
        $this->eventIds = [];

        foreach ($this->documentIds as $id) {
            EpcisDocument::query()->whereKey($id)->delete();
        }
        $this->documentIds = [];

        if ($this->epcIds !== []) {
            EpcIlmd::query()->whereIn('epc_id', $this->epcIds)->delete();
            Epc::query()->whereIn('id', $this->epcIds)->delete();
            $this->epcIds = [];
        }

        foreach ($this->principalIds as $id) {
            Principal::query()->whereKey($id)->delete();
        }
        $this->principalIds = [];

        foreach ($this->siteIds as $id) {
            Site::query()->whereKey($id)->delete();
        }
        $this->siteIds = [];

        tenancy()->end();
    }
}
