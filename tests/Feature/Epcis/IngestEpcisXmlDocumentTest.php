<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IngestEpcisXmlDocumentTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    #[Test]
    public function it_ingests_a_minimal_epcis_fixture_into_demo2(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('epcis_documents'));

            $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $this->assertFileExists($fixture);

            // Unique content per run so sha256 idempotency does not short-circuit.
            $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
            $this->assertNotFalse($tmp);
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);
            $uuid = (string) str()->uuid();
            $xml = str_replace('11111111-2222-3333-4444-555555555555', $uuid, $xml);
            file_put_contents($tmp, $xml);

            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
            ]);
            $this->documentId = (int) $document->getKey();

            $this->assertSame('validated', $document->status);
            $this->assertSame($uuid, $document->document_uuid);
            $this->assertSame(3, $document->event_count);
            $this->assertSame(2, $document->epc_count);
            $this->assertTrue($document->dscsa_affirm);
            $this->assertStringContainsString('FDCA', (string) $document->legal_notice);
            if (Schema::hasColumn('epcis_documents', 'sender_gln')) {
                $this->assertSame('0301160000009', $document->sender_gln);
                $this->assertSame('0096295000009', $document->receiver_gln);
            }

            $this->assertSame(3, EpcisEvent::query()->where('document_id', $document->id)->count());
            $this->assertTrue(Epc::query()->where('epc_uri', 'urn:epc:id:sgtin:030116.0200116.10000082001560')->exists());
            $this->assertTrue(Epc::query()->where('epc_uri', 'urn:epc:id:sscc:030116.01001227052')->exists());

            $sgtin = Epc::query()->where('epc_uri', 'urn:epc:id:sgtin:030116.0200116.10000082001560')->first();
            $this->assertNotNull($sgtin);
            $ilmd = EpcIlmd::query()->where('epc_id', $sgtin->id)->first();
            $this->assertNotNull($ilmd);
            $this->assertSame('606412T', $ilmd->lot_number);
            $this->assertSame('2029-05-31', $ilmd->expiry_date?->format('Y-m-d'));

            $eventIds = EpcisEvent::query()->where('document_id', $document->id)->pluck('id');
            $this->assertSame(1, AggregationLink::query()->whereIn('established_by_event_id', $eventIds)->count());
            $this->assertSame(1, DB::table('event_epcs')->whereIn('event_id', $eventIds)->where('role', 'parentID')->count());
            $this->assertSame(1, DB::table('event_epcs')->whereIn('event_id', $eventIds)->where('role', 'childEPC')->count());
            $this->assertSame(2, DB::table('event_epcs')->whereIn('event_id', $eventIds)->where('role', 'epcList')->count());

            @unlink($tmp);
        } finally {
            $this->cleanupIngestFixtures();
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

    private function cleanupIngestFixtures(): void
    {
        if (tenancy()->initialized) {
            if ($this->documentId !== null) {
                EpcisDocument::query()->whereKey($this->documentId)->delete();
            }

            foreach ([
                'urn:epc:id:sgtin:030116.0200116.10000082001560',
                'urn:epc:id:sscc:030116.01001227052',
            ] as $uri) {
                $epc = Epc::query()->where('epc_uri', $uri)->first();
                if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                    $epc->delete();
                }
            }

            tenancy()->end();
        }
    }
}
