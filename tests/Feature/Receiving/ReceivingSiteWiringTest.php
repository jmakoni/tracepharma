<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Services\Tracing\BuildAssetTrace;
use App\Support\Gs1\Sgln;
use App\Support\Receiving\EligibleReceiveSites;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceivingSiteWiringTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private const RECEIVE_SITE_GLN = '0366159000089';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?int $sessionId = null;

    private ?int $receivingDocumentId = null;

    private ?int $siteId = null;

    #[Test]
    public function open_session_resolves_non_null_site_id_without_explicit_choice(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertGreaterThanOrEqual(1, EligibleReceiveSites::forOrganization()->count());

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->sessionId = (int) $session->getKey();

            $this->assertNotNull($session->site_id);
            $site = Site::query()->find($session->site_id);
            $this->assertNotNull($site);
            $this->assertTrue($site->is_active);
            $this->assertNotEmpty($site->gln);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function completed_receive_authors_events_with_site_glns_and_trace_last_seen_label(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = Site::query()->create([
                'name' => 'Receiving Site Wiring Test',
                'gln' => self::RECEIVE_SITE_GLN,
                'is_active' => true,
                'is_headquarters' => false,
                'is_organization_facility' => true,
            ]);
            $this->siteId = (int) $site->getKey();

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle(
                $document,
                siteId: $this->siteId,
            );
            $this->sessionId = (int) $session->getKey();

            $this->assertSame($this->siteId, $session->site_id);

            app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                userId: null,
                autoConfirmChildren: true,
            );

            $session->refresh();
            $this->assertSame('completed', $session->status);
            $this->assertNotNull($session->receiving_epcis_document_id);
            $this->receivingDocumentId = (int) $session->receiving_epcis_document_id;

            $event = EpcisEvent::query()
                ->where('document_id', $session->receiving_epcis_document_id)
                ->where('event_type', 'ObjectEvent')
                ->where('biz_step', 'urn:epcglobal:cbv:bizstep:receiving')
                ->firstOrFail();

            $expectedGln = Sgln::normalizeGln(self::RECEIVE_SITE_GLN);
            $this->assertSame($expectedGln, $event->biz_location_gln);
            $this->assertSame($expectedGln, $event->read_point_gln);

            $trace = app(BuildAssetTrace::class)->handle(self::SGTIN_URI);
            $this->assertTrue($trace['found']);
            $this->assertNotEmpty($trace['last_seen_at']);
        } finally {
            $this->cleanup();
        }
    }

    private function ingestMinimalFixture(): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) str()->uuid();
        $xml = str_replace('11111111-2222-3333-4444-555555555555', $uuid, $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
            ]);
        } finally {
            @unlink($tmp);
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

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->receivingDocumentId !== null) {
                $receivingDocument = EpcisDocument::query()->find($this->receivingDocumentId);
                if ($receivingDocument !== null && filled($receivingDocument->payload_path)) {
                    Storage::disk($receivingDocument->payload_disk)->delete($receivingDocument->payload_path);
                }
                EpcisDocument::query()->whereKey($this->receivingDocumentId)->delete();
                $this->receivingDocumentId = null;
            }

            if ($this->sessionId !== null) {
                ReceivingSession::query()->whereKey($this->sessionId)->delete();
                $this->sessionId = null;
            }

            if ($this->documentId !== null) {
                $session = ReceivingSession::query()->where('epcis_document_id', $this->documentId)->first();
                if ($session !== null && $session->receiving_epcis_document_id !== null) {
                    EpcisDocument::query()->whereKey($session->receiving_epcis_document_id)->delete();
                }
                ReceivingSession::query()->where('epcis_document_id', $this->documentId)->delete();
                EpcisDocument::query()->whereKey($this->documentId)->delete();
                $this->documentId = null;
            }

            if ($this->siteId !== null) {
                Site::query()->whereKey($this->siteId)->delete();
                $this->siteId = null;
            }

            foreach ([self::SGTIN_URI, self::SSCC_URI] as $uri) {
                $epc = Epc::query()->where('epc_uri', $uri)->first();
                if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                    $epc->delete();
                }
            }

            tenancy()->end();
        }
    }
}
