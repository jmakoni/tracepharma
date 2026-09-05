<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\OpenTransferReceivingSession;
use App\Actions\Transferring\CompleteTransferringSession;
use App\Actions\Transferring\ConfirmTransferringScan;
use App\Actions\Transferring\OpenTransferringSession;
use App\Enums\TenantProfile;
use App\Jobs\Receiving\NotifyWmsReceiveConfirm;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Transferring\TransferringSession;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

class NotifyWmsReceiveConfirmTest extends TestCase
{
    use PreparesDemo2ReceivingState;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private const WMS_URL = 'https://wms.example.test/receive-confirm';

    private const WMS_KEY = 'wms-receive-confirm-test-key';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?int $sessionId = null;

    private ?int $receivingDocumentId = null;

    private ?int $transferSessionId = null;

    private ?int $transferDocumentId = null;

    private ?int $epcId = null;

    private ?string $epcUri = null;

    /** @var list<int> */
    private array $custodyDocumentIds = [];

    /** @var list<int> */
    private array $custodyEventIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $extraEpcIds = [];

    private ?string $priorWmsUrl = null;

    private ?string $priorWmsKey = null;

    private ?bool $priorWmsKilled = null;

    private ?ReceivingEdgeMode $priorEdgeMode = null;

    private ?bool $priorJobRolesEnabled = null;

    #[Test]
    public function inbound_asn_complete_posts_receive_confirm_with_idempotency_and_scans(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->enableWmsReceiveConfirm();

            Http::fake([
                self::WMS_URL => Http::response(['ok' => true], 200),
            ]);

            $session = $this->completeInboundAsnSession();
            $session->refresh();

            $this->assertSame('completed', $session->status);
            $this->assertNotNull($session->receiving_events_generated_at);
            $this->assertNotNull($session->completed_at);
            $this->assertNotNull($session->wms_receive_confirmed_at);

            $expectedKey = 'receive:'.self::DEMO2_TENANT_ID.':'.$session->getKey().':'
                .$session->completed_at->toIso8601String();

            Http::assertSentCount(1);
            Http::assertSent(function ($request) use ($session, $expectedKey): bool {
                $payload = $request->data();

                return $request->url() === self::WMS_URL
                    && $request->hasHeader('Authorization', 'Bearer '.self::WMS_KEY)
                    && $request->hasHeader('X-Wms-Api-Key', self::WMS_KEY)
                    && $request->hasHeader('Idempotency-Key', $expectedKey)
                    && (int) ($payload['session_id'] ?? 0) === (int) $session->getKey()
                    && isset($payload['scans'])
                    && is_array($payload['scans'])
                    && in_array(self::SSCC_URI, $payload['scans'], true);
            });
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function already_confirmed_session_skips_http(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->enableWmsReceiveConfirm();

            Http::fake([
                self::WMS_URL => Http::response(['ok' => true], 200),
            ]);

            $session = $this->completeInboundAsnSession();
            $session->refresh();
            $this->assertNotNull($session->wms_receive_confirmed_at);

            Http::fake([
                self::WMS_URL => Http::response(['ok' => true], 200),
            ]);

            (new NotifyWmsReceiveConfirm(self::DEMO2_TENANT_ID, (int) $session->getKey()))->handle();

            Http::assertNothingSent();
            $this->assertSame('completed', $session->fresh()->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function no_url_does_not_dispatch_or_post(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->enableWmsReceiveConfirm(url: null);

            Queue::fake();
            Http::fake();

            $session = $this->completeInboundAsnSession();

            $this->assertSame('completed', $session->fresh()->status);
            Queue::assertNotPushed(NotifyWmsReceiveConfirm::class);
            Http::assertNothingSent();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function transfer_receive_complete_does_not_post(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->enableWmsReceiveConfirm();

            Http::fake([
                self::WMS_URL => Http::response(['ok' => true], 200),
            ]);

            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $shipped = $this->shipOneEpc($fromSite, $toSite);

            $receiving = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $this->sessionId = (int) $receiving->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($receiving, (string) $this->epcUri);
            $this->assertTrue($confirm['ok']);

            $receiving->refresh();
            $this->assertSame('completed', $receiving->status);

            Http::assertNothingSent();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function kill_switch_prevents_http(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->enableWmsReceiveConfirm();
            TenantSettings::forTenant(tenant())->setKillSwitch(TenantKillSwitches::WMS_WEBHOOKS, true);
            tenant()->save();

            Http::fake([
                self::WMS_URL => Http::response(['ok' => true], 200),
            ]);

            $session = $this->completeInboundAsnSession();

            $this->assertSame('completed', $session->fresh()->status);
            Http::assertNothingSent();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function http_500_keeps_session_completed_and_rethrows(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->enableWmsReceiveConfirm();
            Queue::fake();

            Http::fake([
                self::WMS_URL => Http::response('WMS unavailable', 500),
            ]);

            $session = $this->completeInboundAsnSession();
            $session->refresh();

            $this->assertSame('completed', $session->status);
            $this->assertNotNull($session->completed_at);
            $this->assertNotNull($session->receiving_events_generated_at);
            $this->assertNull($session->wms_receive_confirmed_at);

            $job = new NotifyWmsReceiveConfirm(self::DEMO2_TENANT_ID, (int) $session->getKey());
            $this->assertSame(3, $job->tries);
            $this->assertSame(600, $job->timeout);

            try {
                $job->handle();
                $this->fail('Expected WMS receive-confirm HTTP 500 to throw.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('HTTP 500', $e->getMessage());
            }

            $session->refresh();
            $this->assertSame('completed', $session->status);
            $this->assertNull($session->wms_receive_confirmed_at);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function oversized_scan_payload_is_chunked_with_per_chunk_idempotency_keys(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->enableWmsReceiveConfirm();
            Config::set('integrations.wms.receive_confirm_max_scans', 2);
            Queue::fake();

            Http::fake([
                self::WMS_URL => Http::response(['ok' => true], 200),
            ]);

            $session = $this->completeInboundAsnSession();
            $this->assertNull($session->fresh()->wms_receive_confirmed_at);

            $this->appendConfirmedScanLines($session, 3);

            (new NotifyWmsReceiveConfirm(self::DEMO2_TENANT_ID, (int) $session->getKey()))->handle();

            $session->refresh();
            $this->assertNotNull($session->wms_receive_confirmed_at);
            $this->assertSame('completed', $session->status);

            $baseKey = 'receive:'.self::DEMO2_TENANT_ID.':'.$session->getKey().':'
                .$session->completed_at->toIso8601String();

            Http::assertSentCount(3);
            Http::assertSent(fn ($request): bool => $request->hasHeader('Idempotency-Key', $baseKey.'-chunk-0')
                && count($request->data()['scans'] ?? []) === 2);
            Http::assertSent(fn ($request): bool => $request->hasHeader('Idempotency-Key', $baseKey.'-chunk-1')
                && count($request->data()['scans'] ?? []) === 2);
            Http::assertSent(fn ($request): bool => $request->hasHeader('Idempotency-Key', $baseKey.'-chunk-2')
                && count($request->data()['scans'] ?? []) === 1);
        } finally {
            Config::set('integrations.wms.receive_confirm_max_scans', 5000);
            $this->cleanup();
        }
    }

    #[Test]
    public function chunk_failure_does_not_stamp_confirmed_at(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->enableWmsReceiveConfirm();
            Config::set('integrations.wms.receive_confirm_max_scans', 2);
            Queue::fake();

            Http::fake([
                self::WMS_URL => Http::sequence()
                    ->push(['ok' => true], 200)
                    ->push('WMS unavailable', 500),
            ]);

            $session = $this->completeInboundAsnSession();
            $this->appendConfirmedScanLines($session, 3);

            try {
                (new NotifyWmsReceiveConfirm(self::DEMO2_TENANT_ID, (int) $session->getKey()))->handle();
                $this->fail('Expected mid-chunk HTTP failure to throw.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('HTTP 500', $e->getMessage());
            }

            $session->refresh();
            $this->assertSame('completed', $session->status);
            $this->assertNull($session->wms_receive_confirmed_at);
            Http::assertSentCount(2);
        } finally {
            Config::set('integrations.wms.receive_confirm_max_scans', 5000);
            $this->cleanup();
        }
    }

    private function appendConfirmedScanLines(ReceivingSession $session, int $count): void
    {
        $now = now();

        for ($i = 0; $i < $count; $i++) {
            $uri = 'urn:epc:id:sgtin:030116.0200116.WMSCHUNK'.str_pad((string) $i, 8, '0', STR_PAD_LEFT);
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->extraEpcIds[] = (int) $epc->getKey();

            ReceivingScanLine::query()->create([
                'receiving_session_id' => $session->getKey(),
                'epc_id' => $epc->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'child',
                'status' => 'confirmed',
                'scan_raw' => $uri,
                'confirmed_at' => $now,
            ]);
        }
    }

    private function enableWmsReceiveConfirm(?string $url = self::WMS_URL): void
    {
        $this->ensureDemo2OrgPrefixMatchesReceiveSites();

        $settings = TenantSettings::forTenant(tenant());
        $this->priorWmsUrl = $settings->wmsReceiveConfirmUrl();
        $this->priorWmsKey = $settings->wmsBridgeApiKey();
        $this->priorWmsKilled = $settings->wmsWebhooksKilled();

        $settings->setWmsReceiveConfirmUrl($url);
        $settings->setWmsBridgeApiKey(self::WMS_KEY);
        tenant()->saveQuietly();
    }

    private function completeInboundAsnSession(): ReceivingSession
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
        $this->assertSame('completed', $session->status);
        $this->assertNotNull($session->receiving_epcis_document_id);
        $this->receivingDocumentId = (int) $session->receiving_epcis_document_id;

        return $session;
    }

    private function ingestMinimalFixture(): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) Str::uuid();
        $xml = str_replace('11111111-2222-3333-4444-555555555555', $uuid, $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => basename($fixture),
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createTransferSites(Tenant $tenant): array
    {
        $fromSite = Site::query()->create([
            'name' => 'WMS Receive From '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $fromSite->getKey();

        $toSite = Site::query()->create([
            'name' => 'WMS Receive To '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => false,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $toSite->getKey();

        $settings = TenantSettings::forTenant($tenant);
        $settings->setDefaultShipFromSiteId((int) $fromSite->getKey());
        $settings->setDefaultReceiveSiteId((int) $toSite->getKey());
        $tenant->save();

        return [$fromSite, $toSite];
    }

    private function shipOneEpc(Site $fromSite, Site $toSite): TransferringSession
    {
        $suffix = (string) random_int(10000000, 99999999);
        $this->epcUri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.TR'.$suffix;

        $transfer = app(OpenTransferringSession::class)->handle(
            fromSiteId: (int) $fromSite->getKey(),
            toSiteId: (int) $toSite->getKey(),
        );
        $this->transferSessionId = (int) $transfer->getKey();

        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($this->epcUri));
        $this->epcId = (int) $epc->getKey();
        $this->receiveAtSite($fromSite, $epc);

        app(ConfirmTransferringScan::class)->handle($transfer, $this->epcUri);

        $shipped = app(CompleteTransferringSession::class)->handle($transfer->fresh());
        $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

        return $shipped;
    }

    private function receiveAtSite(Site $site, Epc $epc): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'wms-receive-confirm-custody.xml',
        ]);
        $this->custodyDocumentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => now()->subMinute(),
            'record_time' => now()->subMinute(),
            'event_timezone_offset' => '+00:00',
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:receiving',
            'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
            'read_point_gln' => (string) $site->gln,
            'biz_location_gln' => (string) $site->gln,
        ]);
        $this->custodyEventIds[] = (int) $event->getKey();

        DB::table('event_epcs')->insertOrIgnore([[
            'event_id' => $event->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]]);
    }

    private function uniqueGln(): string
    {
        do {
            $gln = '0366159'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (Site::query()->where('gln', $gln)->exists());

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

        $settings = TenantSettings::forTenant($tenant);
        $this->priorJobRolesEnabled = $settings->jobRolesEnabled();
        if ($this->priorJobRolesEnabled) {
            $settings->setJobRolesEnabled(false);
            $tenant->save();
        }

        if (blank(TenantSettings::forTenant($tenant)->gln())) {
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);
        }

        $this->prepareDemo2ReceivingState([self::SSCC_URI, self::SGTIN_URI]);

        $settings = TenantSettings::forTenant($tenant);
        $this->priorEdgeMode = $settings->receivingEdgeMode();
        $settings->setReceivingEdgeMode(ReceivingEdgeMode::SealedParent);
        $tenant->save();

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
            $session = ReceivingSession::query()->find($this->sessionId);
            if ($session !== null && $session->receiving_epcis_document_id !== null) {
                EpcisDocument::query()->whereKey($session->receiving_epcis_document_id)->delete();
            }
            ReceivingScanLine::query()->where('receiving_session_id', $this->sessionId)->delete();
            ReceivingSession::query()->whereKey($this->sessionId)->delete();
            $this->sessionId = null;
        }

        if ($this->documentId !== null) {
            EpcisDocument::query()->whereKey($this->documentId)->delete();
            $this->documentId = null;
        }

        if ($this->transferDocumentId !== null) {
            $doc = EpcisDocument::query()->find($this->transferDocumentId);
            if ($doc !== null && filled($doc->payload_path)) {
                Storage::disk($doc->payload_disk)->delete($doc->payload_path);
            }
            EpcisDocument::query()->whereKey($this->transferDocumentId)->delete();
            $this->transferDocumentId = null;
        }

        if ($this->transferSessionId !== null) {
            TransferringSession::query()->whereKey($this->transferSessionId)->delete();
            $this->transferSessionId = null;
        }

        if ($this->custodyEventIds !== []) {
            DB::table('event_epcs')->whereIn('event_id', $this->custodyEventIds)->delete();
            EpcisEvent::query()->whereIn('id', $this->custodyEventIds)->delete();
            $this->custodyEventIds = [];
        }

        if ($this->custodyDocumentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->custodyDocumentIds)->delete();
            $this->custodyDocumentIds = [];
        }

        if ($this->extraEpcIds !== []) {
            ReceivingScanLine::query()->whereIn('epc_id', $this->extraEpcIds)->delete();
            QuarantineHold::query()->whereIn('epc_id', $this->extraEpcIds)->delete();
            DB::table('event_epcs')->whereIn('epc_id', $this->extraEpcIds)->delete();
            Epc::query()->whereIn('id', $this->extraEpcIds)->delete();
            $this->extraEpcIds = [];
        }

        if ($this->epcId !== null) {
            ReceivingScanLine::query()->where('epc_id', $this->epcId)->delete();
            QuarantineHold::query()->where('epc_id', $this->epcId)->delete();
            DB::table('event_epcs')->where('epc_id', $this->epcId)->delete();
            Epc::query()->whereKey($this->epcId)->delete();
            $this->epcId = null;
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        $this->prepareDemo2ReceivingState([self::SSCC_URI, self::SGTIN_URI]);

        $settings = TenantSettings::forTenant(tenant());
        $settings->setReceivingEdgeMode($this->priorEdgeMode);
        $this->priorEdgeMode = null;
        if ($this->priorJobRolesEnabled !== null) {
            $settings->setJobRolesEnabled($this->priorJobRolesEnabled);
            $this->priorJobRolesEnabled = null;
        }
        tenant()->save();

        if ($this->priorWmsUrl !== null || $this->priorWmsKey !== null || $this->priorWmsKilled !== null) {
            $settings->saveOrganization([
                'wms_receive_confirm_url' => $this->priorWmsUrl,
            ]);
            $settings->setWmsBridgeApiKey($this->priorWmsKey);
            $settings->setKillSwitch(TenantKillSwitches::WMS_WEBHOOKS, (bool) $this->priorWmsKilled);
            tenant()->save();
            $this->priorWmsUrl = null;
            $this->priorWmsKey = null;
            $this->priorWmsKilled = null;
        }

        tenancy()->end();
    }
}
