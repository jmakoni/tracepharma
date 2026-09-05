<?php

declare(strict_types=1);

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\RepairMissingReceivingEpcisEvents;
use App\Enums\EpcisReceivedVia;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Services\Tracing\BuildAssetTrace;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

class RepairMissingReceivingEpcisEventsTest extends TestCase
{
    use PreparesDemo2ReceivingState;
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?int $sessionId = null;

    private ?int $receivingDocumentId = null;

    #[Test]
    public function repairs_completed_session_with_null_receiving_document(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $session = $this->completeSessionThenBreakReceivingDoc();

            $summary = app(RepairMissingReceivingEpcisEvents::class)->handle();
            $this->assertGreaterThanOrEqual(1, $summary['repaired']);

            $session->refresh();
            $this->assertNotNull($session->receiving_epcis_document_id);
            $this->receivingDocumentId = (int) $session->receiving_epcis_document_id;

            $trace = app(BuildAssetTrace::class)->handle(self::SSCC_URI);
            $this->assertTrue($trace['found']);
            $this->assertNotEmpty($trace['last_seen_at'] ?? null);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function artisan_command_repairs_tenant_sessions(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $session = $this->completeSessionThenBreakReceivingDoc();
            $sessionId = (int) $session->getKey();

            tenancy()->end();

            $exit = Artisan::call('tracepharma:repair-missing-receiving-epcis', [
                '--tenant' => self::DEMO2_TENANT_ID,
                '--session' => $sessionId,
            ]);
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('repaired=1', Artisan::output());

            tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));
            $session = ReceivingSession::query()->findOrFail($sessionId);
            $this->assertNotNull($session->receiving_epcis_document_id);
            $this->receivingDocumentId = (int) $session->receiving_epcis_document_id;
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));
            }
            $this->cleanup();
        }
    }

    private function completeSessionThenBreakReceivingDoc(): ReceivingSession
    {
        $document = $this->ingestMinimalFixture();
        $this->documentId = (int) $document->getKey();

        $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
        $this->sessionId = (int) $session->getKey();

        app(ConfirmReceivingScan::class)->handle($session, self::SSCC_URI, userId: null, autoConfirmChildren: true);
        $session->refresh();
        if ($session->status !== 'completed') {
            $session = app(CompleteReceivingSession::class)->handle($session);
        }
        $this->assertNotNull($session->receiving_epcis_document_id);
        $this->receivingDocumentId = (int) $session->receiving_epcis_document_id;

        EpcisDocument::query()->whereKey($this->receivingDocumentId)->delete();
        $session->forceFill([
            'receiving_epcis_document_id' => null,
            'receiving_events_generated_at' => now(),
        ])->save();

        return $session->refresh();
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
                'received_via' => EpcisReceivedVia::FilamentUpload,
                'original_filename' => basename($fixture),
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

        $this->ensureDemo2OrgPrefixMatchesReceiveSites();

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

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
            EpcisDocument::query()->whereKey($this->documentId)->delete();
            $this->documentId = null;
        }

        $epc = Epc::query()->where('epc_uri', self::SSCC_URI)->first();
        if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
            $epc->delete();
        }

        tenancy()->end();
    }
}
