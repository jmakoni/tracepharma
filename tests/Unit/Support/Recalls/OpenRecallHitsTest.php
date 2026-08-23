<?php

namespace Tests\Unit\Support\Recalls;

use App\Enums\TracingRequestorType;
use App\Enums\TracingRequestScope;
use App\Enums\TracingRequestStatus;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TracingRequest;
use App\Support\Recalls\OpenRecallHits;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PlacesEpcOnHandAtSite;
use Tests\TestCase;

class OpenRecallHitsTest extends TestCase
{
    use PlacesEpcOnHandAtSite;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $requestIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    #[Test]
    public function high_id_on_hand_hit_is_not_dropped_behind_non_matching_rows(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = $this->makeSite();
            $this->makeOnHandSgtin($site, 'OTHERLOT');
            $hit = $this->makeOnHandSgtin($site, 'HITLOT');

            $request = TracingRequest::query()->create([
                'title' => 'Hits high-id lot',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'gtin' => $hit->gtin14,
                'lot' => 'HITLOT',
                'is_recall' => true,
                'requested_at' => now(),
            ]);
            $this->requestIds[] = (int) $request->getKey();

            $rows = app(OpenRecallHits::class)->epcsAtSite((int) $site->getKey(), cap: 1);

            $this->assertTrue($rows->contains(fn (Epc $epc): bool => (int) $epc->getKey() === (int) $hit->getKey()));
            $this->assertFalse(app(OpenRecallHits::class)->isTruncated((int) $site->getKey(), cap: 1));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function display_cap_reports_truncation_instead_of_dropping_quietly(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = $this->makeSite();
            $itemRef = '123456';
            $first = $this->makeOnHandSgtin($site, 'CAPLOT', $itemRef);
            $second = $this->makeOnHandSgtin($site, 'CAPLOT', $itemRef);
            $this->assertSame($first->gtin14, $second->gtin14);
            $this->assertNotNull($first->gtin14);

            $request = TracingRequest::query()->create([
                'title' => 'Two hits one cap',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'gtin' => $first->gtin14,
                'lot' => 'CAPLOT',
                'is_recall' => true,
                'requested_at' => now(),
            ]);
            $this->requestIds[] = (int) $request->getKey();

            $hits = app(OpenRecallHits::class);
            $rows = $hits->epcsAtSite((int) $site->getKey(), cap: 1);

            $this->assertCount(1, $rows);
            $this->assertTrue($hits->isTruncated((int) $site->getKey(), cap: 1));
            $this->assertContains((int) $rows->first()->getKey(), [
                (int) $first->getKey(),
                (int) $second->getKey(),
            ]);
        } finally {
            $this->cleanup();
        }
    }

    private function makeSite(): Site
    {
        $gln = '03'.str_pad((string) random_int(0, 99_999_999_999), 11, '0', STR_PAD_LEFT);
        $site = Site::query()->create([
            'name' => 'Recall hits site '.substr((string) str()->uuid(), 0, 8),
            'gln' => $gln,
            'is_active' => true,
            'is_headquarters' => false,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        return $site;
    }

    private function makeOnHandSgtin(Site $site, string $lot, ?string $itemRef = null): Epc
    {
        $suffix = (string) random_int(10_000_000, 99_999_999);
        $itemRef ??= substr($suffix, 0, 6);
        $epc = Epc::fromUri('urn:epc:id:sgtin:030116.3'.$itemRef.'.RH'.$suffix);
        $epc->first_seen_at = now();
        $epc->save();
        $this->epcIds[] = (int) $epc->getKey();

        EpcIlmd::query()->create([
            'epc_id' => $epc->getKey(),
            'gtin14' => $epc->gtin14,
            'lot_number' => $lot,
        ]);

        $placed = $this->placeEpcOnHandAtSite($site, $epc);
        $this->documentIds[] = (int) $placed['document']->getKey();
        $this->eventIds[] = (int) $placed['event']->getKey();

        return $epc->fresh();
    }

    private function initializeDemo2Tenant(): void
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
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
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->requestIds as $id) {
            TracingRequest::query()->whereKey($id)->delete();
        }
        $this->requestIds = [];

        foreach ($this->eventIds as $eventId) {
            DB::table('event_epcs')->where('event_id', $eventId)->delete();
            EpcisEvent::query()->whereKey($eventId)->delete();
        }
        $this->eventIds = [];

        foreach ($this->documentIds as $documentId) {
            EpcisDocument::query()->whereKey($documentId)->delete();
        }
        $this->documentIds = [];

        foreach ($this->epcIds as $epcId) {
            EpcIlmd::query()->where('epc_id', $epcId)->delete();
            if (! DB::table('event_epcs')->where('epc_id', $epcId)->exists()
                && ! DB::table('document_epcs')->where('epc_id', $epcId)->exists()) {
                Epc::query()->whereKey($epcId)->delete();
            }
        }
        $this->epcIds = [];

        foreach ($this->siteIds as $siteId) {
            Site::query()->whereKey($siteId)->delete();
        }
        $this->siteIds = [];

        tenancy()->end();
    }
}
