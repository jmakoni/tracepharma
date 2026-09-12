<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EventLocation;
use App\Models\Epcis\EventParty;
use App\Models\Site;
use App\Models\Tenant;
use App\Support\Gs1\Gtin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeaPrefixedLocationIngestTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const DEA_TOKEN = 'DEA:AB1234567';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    /** @var list<int> */
    private array $tenantSiteIds = [];

    #[Test]
    public function ingest_resolves_dea_read_point_and_source_to_site_gln(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasColumn('epcis_documents', 'ship_from_gln'));

            $body12 = fake()->unique()->numerify('############');
            $siteGln = $body12.Gtin::checkDigit($body12);

            $site = Site::factory()->create([
                'gln' => $siteGln,
                'dea_number' => 'AB1234567',
                'is_organization_facility' => false,
            ]);
            $this->tenantSiteIds[] = (int) $site->getKey();

            $fixture = base_path('tests/Fixtures/epcis/dea_prefixed_source_location.xml');
            $this->assertFileExists($fixture);

            $tmp = tempnam(sys_get_temp_dir(), 'epcis_dea_');
            $this->assertNotFalse($tmp);
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);
            $uuid = (string) str()->uuid();
            $xml = str_replace('33333333-4444-5555-6666-777777777777', $uuid, $xml);
            file_put_contents($tmp, $xml);

            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'dea_prefixed_source_location.xml',
            ]);
            $this->documentId = (int) $document->getKey();

            $this->assertSame('validated', $document->status);
            $this->assertSame($siteGln, $document->fresh()->ship_from_gln);
            $this->assertSame((int) $site->getKey(), $document->fresh()->ship_from_site_id);

            $eventIds = $document->events()->pluck('id')->all();
            $this->assertNotEmpty($eventIds);

            $deaLocations = EventLocation::query()
                ->whereIn('event_id', $eventIds)
                ->where('gln_uri', self::DEA_TOKEN)
                ->get();
            $this->assertNotEmpty($deaLocations);

            foreach ($deaLocations as $location) {
                $this->assertSame($siteGln, $location->gln);
                $this->assertSame(self::DEA_TOKEN, $location->gln_uri);
                $this->assertStringNotContainsString('DEA', (string) $location->gln);
                $this->assertSame((int) $site->getKey(), $location->site_id);
            }

            $deaSourceParty = EventParty::query()
                ->whereIn('event_id', $eventIds)
                ->where('party_role', 'source')
                ->where('gln_uri', self::DEA_TOKEN)
                ->first();
            $this->assertNotNull($deaSourceParty);
            $this->assertSame($siteGln, $deaSourceParty->gln);
            $this->assertSame((int) $site->getKey(), $deaSourceParty->site_id);

            @unlink($tmp);
        } finally {
            $this->cleanupFixtures();
        }
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

    private function cleanupFixtures(): void
    {
        if (tenancy()->initialized) {
            if ($this->documentId !== null) {
                EpcisDocument::query()->whereKey($this->documentId)->delete();
            }

            if ($this->tenantSiteIds !== []) {
                Site::query()->whereIn('id', $this->tenantSiteIds)->delete();
            }

            foreach ([
                'urn:epc:id:sgtin:030116.0200116.10000082001560',
            ] as $uri) {
                $epc = Epc::query()->where('epc_uri', $uri)->first();
                if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                    $epc->delete();
                }
            }

            tenancy()->end();
        }

        $this->documentId = null;
        $this->tenantSiteIds = [];
    }
}
