<?php

namespace Tests\Feature\Transferring;

use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Actions\Transferring\CompleteTransferringSession;
use App\Actions\Transferring\ConfirmTransferringReceiveScan;
use App\Actions\Transferring\ConfirmTransferringScan;
use App\Actions\Transferring\GenerateTransferringEpcisEvents;
use App\Actions\Transferring\GenerateTransferringReceiveEpcisEvents;
use App\Actions\Transferring\OpenTransferringSession;
use App\Actions\Receiving\OpenTransferReceivingSession;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use App\Services\Quarantine\QuarantineService;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Epcis\ResolveSiteLocationGlns;
use App\Support\Gs1\Gtin;
use App\Support\Gs1\Sgln;
use App\Support\Shipping\ResolveShipFromSite;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class TransferringSessionTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const EPC_URI = 'urn:epc:id:sgtin:030116.0200116.90000082009999';

    private const EPC_URI_2 = 'urn:epc:id:sgtin:030116.0200116.90000082008888';

    private const SHIP_BLOCK_EPC_URI = 'urn:epc:id:sgtin:030116.0200116.90000082006661';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    private ?int $sessionId = null;

    private ?int $shipSessionId = null;

    private ?int $epcId = null;

    /** @var list<int> */
    private array $custodyDocumentIds = [];

    /** @var list<int> */
    private array $custodyEventIds = [];

    private ?int $transferDocumentId = null;

    /** Ship-leg payload, kept on disk once the receive artifact moves to its own path. */
    private ?string $shipPayloadDisk = null;

    private ?string $shipPayloadPath = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    private ?string $fromGln = null;

    private ?string $toGln = null;

    private ?string $priorCompanyPrefix = null;

    private ?string $priorTenantGln = null;

    #[Test]
    public function ship_authors_shipping_event_only_then_receive_completes_with_receiving_event(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $this->assertSame('open', $session->status);
            $this->assertSame((int) $fromSite->getKey(), (int) $session->from_site_id);
            $this->assertSame((int) $toSite->getKey(), (int) $session->to_site_id);
            $this->assertSame(0, (int) $session->confirmed_count);

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            $confirm = app(ConfirmTransferringScan::class)->handle($session, self::EPC_URI);
            $this->assertTrue($confirm['ok']);
            $this->assertSame('confirmed', $confirm['effect']);
            $this->assertSame(1, (int) $session->fresh()->confirmed_count);

            $shipped = app(CompleteTransferringSession::class)->handle($session->fresh());
            $this->assertSame('in_transit', $shipped->status);
            $this->assertNotNull($shipped->shipped_at);
            $this->assertNotNull($shipped->transfer_events_generated_at);
            $this->assertNotNull($shipped->transfer_epcis_document_id);
            $this->assertNull($shipped->receive_events_generated_at);
            $this->assertNull($shipped->completed_at);
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $document = EpcisDocument::query()->findOrFail($shipped->transfer_epcis_document_id);
            $this->assertSame('outbound', $document->direction);
            $this->assertSame(1, (int) $document->event_count);
            $this->assertSame('parsed', $document->status);
            $this->assertStringContainsString('transferring session', (string) $document->notes);
            $this->assertTrue(Storage::disk($document->payload_disk)->exists($document->payload_path));
            $this->shipPayloadDisk = (string) $document->payload_disk;
            $this->shipPayloadPath = (string) $document->payload_path;

            $eventsAfterShip = EpcisEvent::query()
                ->where('document_id', $document->getKey())
                ->orderBy('id')
                ->get();

            $this->assertCount(1, $eventsAfterShip);

            $shipping = $eventsAfterShip->firstWhere('biz_step', 'urn:epcglobal:cbv:bizstep:shipping');
            $this->assertNotNull($shipping);
            $this->assertSame('OBSERVE', $shipping->action);
            $this->assertSame('urn:epcglobal:cbv:disp:in_transit', $shipping->disposition);
            $this->assertSame($this->fromGln, $shipping->read_point_gln);
            $this->assertSame($this->fromGln, $shipping->biz_location_gln);
            $this->assertNull(
                $eventsAfterShip->firstWhere('biz_step', 'urn:epcglobal:cbv:bizstep:receiving'),
            );

            $prefix = TenantSettings::forTenant($tenant)->companyPrefix();
            $this->assertNotNull($prefix);
            $expectedFromUrn = Sgln::toUrn($this->fromGln, strlen($prefix));
            $this->assertNotNull($expectedFromUrn);
            $shipPayload = Storage::disk($document->payload_disk)->get($document->payload_path);
            $this->assertIsString($shipPayload);
            // Site has GLN but only the non-GS1 generated sgln column — resolver must still
            // build readPoint/bizLocation from GLN + tenant company prefix.
            $this->assertStringContainsString('<readPoint>', $shipPayload);
            $this->assertStringContainsString('<bizLocation>', $shipPayload);
            $this->assertStringContainsString('<id>'.$expectedFromUrn.'</id>', $shipPayload);

            $receive = app(ConfirmTransferringReceiveScan::class)->handle(
                $shipped->fresh(),
                self::EPC_URI,
                generateReceiveEvents: true,
            );
            $this->assertTrue($receive['ok']);
            $this->assertTrue($receive['session_completed']);
            $this->assertSame('completed', $receive['effect']);

            $completed = $session->fresh();
            $this->assertSame('completed', $completed->status);
            $this->assertSame(1, (int) $completed->received_count);
            $this->assertNotNull($completed->received_at);
            $this->assertNotNull($completed->completed_at);
            $this->assertNotNull($completed->receive_events_generated_at);
            $this->assertSame($this->transferDocumentId, (int) $completed->transfer_epcis_document_id);

            $document->refresh();
            $this->assertSame(2, (int) $document->event_count);

            $eventsAfterReceive = EpcisEvent::query()
                ->where('document_id', $document->getKey())
                ->orderBy('id')
                ->get();

            $this->assertCount(2, $eventsAfterReceive);

            $receiving = $eventsAfterReceive->firstWhere('biz_step', 'urn:epcglobal:cbv:bizstep:receiving');
            $this->assertNotNull($receiving);
            $this->assertSame('OBSERVE', $receiving->action);
            $this->assertSame('urn:epcglobal:cbv:disp:in_progress', $receiving->disposition);
            $this->assertSame($this->toGln, $receiving->read_point_gln);
            $this->assertSame($this->toGln, $receiving->biz_location_gln);

            $expectedToUrn = Sgln::toUrn($this->toGln, strlen($prefix));
            $this->assertNotNull($expectedToUrn);
            $receivePayload = Storage::disk($document->payload_disk)->get($document->payload_path);
            $this->assertIsString($receivePayload);
            $this->assertStringContainsString('<id>'.$expectedFromUrn.'</id>', $receivePayload);
            $this->assertStringContainsString('<id>'.$expectedToUrn.'</id>', $receivePayload);
            $this->assertSame(2, substr_count($receivePayload, '<readPoint>'));
            $this->assertSame(2, substr_count($receivePayload, '<bizLocation>'));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function transfer_receive_transmits_the_new_payload_and_leaves_the_ship_file_intact(): void
    {
        config(['tracepharma.epcis_jobs.enabled' => false]);

        $tenant = $this->initializeDemo2Tenant();

        try {
            /** @var list<array{document_id: int, payload_path: string, document_uuid: string}> $transmitted */
            $transmitted = [];
            $this->mock(OutboundEpcisTransmitter::class, function (MockInterface $mock) use (&$transmitted): void {
                $mock->shouldReceive('transmit')->andReturnUsing(
                    function (EpcisDocument $document) use (&$transmitted): void {
                        $transmitted[] = [
                            'document_id' => (int) $document->getKey(),
                            'payload_path' => (string) $document->payload_path,
                            'document_uuid' => (string) $document->document_uuid,
                        ];
                    },
                );
            });

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($session, self::EPC_URI);
            $shipped = app(CompleteTransferringSession::class)->handle($session->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $document = EpcisDocument::query()->findOrFail($this->transferDocumentId);
            $this->shipPayloadDisk = (string) $document->payload_disk;
            $this->shipPayloadPath = (string) $document->payload_path;
            $shipDocumentUuid = (string) $document->document_uuid;

            $this->assertCount(1, $transmitted, 'The ship leg schedules its own transmission.');
            $this->assertSame($this->shipPayloadPath, $transmitted[0]['payload_path']);

            app(ConfirmTransferringReceiveScan::class)->handle(
                $shipped->fresh(),
                self::EPC_URI,
                generateReceiveEvents: true,
            );

            $document->refresh();
            $receivePayloadPath = (string) $document->payload_path;

            $this->assertNotSame(
                $this->shipPayloadPath,
                $receivePayloadPath,
                'The receive artifact must be written to a path of its own.',
            );
            $this->assertNotSame($shipDocumentUuid, (string) $document->document_uuid);
            $this->assertSame("transfer-{$this->sessionId}-receive.xml", (string) $document->original_filename);

            // The in_transit bytes the ship leg already sent stay readable where
            // they were sent from, so the shipment can still be reproduced.
            $this->assertTrue(Storage::disk($this->shipPayloadDisk)->exists($this->shipPayloadPath));
            $shipXml = (string) Storage::disk($this->shipPayloadDisk)->get($this->shipPayloadPath);
            $this->assertSame(1, substr_count($shipXml, '<ObjectEvent>'));
            $this->assertStringNotContainsString('urn:epcglobal:cbv:bizstep:receiving', $shipXml);

            $this->assertTrue(Storage::disk($document->payload_disk)->exists($receivePayloadPath));
            $receiveXml = (string) Storage::disk($document->payload_disk)->get($receivePayloadPath);
            $this->assertSame(2, substr_count($receiveXml, '<ObjectEvent>'));

            // Intracompany transfers carry no trading partner, so the combined
            // two-event file is scheduled on the same terms as the ship leg.
            $this->assertNull($document->trading_partner_id);
            $this->assertCount(2, $transmitted, 'The receive leg schedules a second transmission.');
            $this->assertSame($this->transferDocumentId, $transmitted[1]['document_id']);
            $this->assertSame($receivePayloadPath, $transmitted[1]['payload_path']);

            $rerun = app(GenerateTransferringReceiveEpcisEvents::class)->handle($session->fresh());

            $this->assertFalse($rerun['generated']);
            $this->assertCount(2, $transmitted, 'A repeated receive must not schedule a third transmission.');
            $this->assertSame($receivePayloadPath, (string) $document->fresh()->payload_path);
            $this->assertSame(1, EpcisEvent::query()
                ->where('document_id', $this->transferDocumentId)
                ->where('biz_step', 'urn:epcglobal:cbv:bizstep:receiving')
                ->count());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function partial_transfer_receive_keeps_original_shipping_epc_list_in_xml(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        /** @var list<int> $extraEpcIds */
        $extraEpcIds = [];

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $receivedEpc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
            $missingEpc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI_2));
            $this->epcId = (int) $receivedEpc->getKey();
            $extraEpcIds[] = (int) $missingEpc->getKey();

            $this->receiveAtSite($fromSite, $receivedEpc);
            $this->receiveAtSite($fromSite, $missingEpc);

            app(ConfirmTransferringScan::class)->handle($session, self::EPC_URI);
            app(ConfirmTransferringScan::class)->handle($session->fresh(), self::EPC_URI_2);

            $shipped = app(CompleteTransferringSession::class)->handle($session->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            app(ConfirmTransferringReceiveScan::class)->handle(
                $shipped->fresh(),
                self::EPC_URI,
                generateReceiveEvents: false,
            );

            // Short receive: one unit never scanned at destination.
            $shipped->forceFill([
                'status' => 'completed',
                'received_count' => 1,
                'received_at' => now(),
                'completed_at' => now(),
            ])->save();

            TransferringScanLine::query()
                ->where('transferring_session_id', $shipped->getKey())
                ->where('epc_id', $receivedEpc->getKey())
                ->update(['status' => 'received', 'received_at' => now()]);

            $result = app(GenerateTransferringReceiveEpcisEvents::class)->handle($shipped->fresh());
            $this->assertTrue($result['generated']);

            $document = EpcisDocument::query()->findOrFail($this->transferDocumentId);
            $receiveXml = (string) Storage::disk($document->payload_disk)->get($document->payload_path);

            $shippingEvent = EpcisEvent::query()
                ->where('document_id', $document->getKey())
                ->where('biz_step', 'urn:epcglobal:cbv:bizstep:shipping')
                ->firstOrFail();

            $shippingUuid = str_replace('urn:uuid:', '', (string) $shippingEvent->event_id);
            $this->assertStringContainsString('<eventID>urn:uuid:'.$shippingUuid.'</eventID>', $receiveXml);

            preg_match_all('/<epcList>\s*(.*?)\s*<\/epcList>/s', $receiveXml, $epcLists);
            $this->assertCount(2, $epcLists[0], 'Receive artifact must contain shipping and receiving epcLists.');

            $shippingEpcList = $epcLists[1][0];
            $receivingEpcList = $epcLists[1][1];

            $this->assertSame(2, substr_count($shippingEpcList, '<epc>'));
            $this->assertStringContainsString(self::EPC_URI, $shippingEpcList);
            $this->assertStringContainsString(self::EPC_URI_2, $shippingEpcList);

            $this->assertSame(1, substr_count($receivingEpcList, '<epc>'));
            $this->assertStringContainsString(self::EPC_URI, $receivingEpcList);
            $this->assertStringNotContainsString(self::EPC_URI_2, $receivingEpcList);

            $receivingEvent = EpcisEvent::query()
                ->where('document_id', $document->getKey())
                ->where('biz_step', 'urn:epcglobal:cbv:bizstep:receiving')
                ->firstOrFail();

            $this->assertSame(
                1,
                DB::table('event_epcs')
                    ->where('event_id', $receivingEvent->getKey())
                    ->where('role', 'epcList')
                    ->count(),
            );
        } finally {
            if (tenancy()->initialized && $extraEpcIds !== []) {
                foreach ($extraEpcIds as $extraEpcId) {
                    DB::table('event_epcs')->where('epc_id', $extraEpcId)->delete();
                    if (Schema::hasTable('document_epcs')) {
                        DB::table('document_epcs')->where('epc_id', $extraEpcId)->delete();
                    }
                    TransferringScanLine::query()->where('epc_id', $extraEpcId)->delete();
                    Epc::query()->whereKey($extraEpcId)->delete();
                }
            }
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function open_transfer_receive_with_expected_leftovers_does_not_author_receive_epcis(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        /** @var list<int> $extraEpcIds */
        $extraEpcIds = [];
        $receivingSessionId = null;
        $suffix = (string) random_int(10000000, 99999999);
        $receivedUri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.LR'.$suffix;
        $missingUri = 'urn:epc:id:sgtin:030116.3'.substr((string) ($suffix + 1), 0, 6).'.LM'.($suffix + 1);

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $receivedEpc = Epc::query()->create(Epc::materializeAttributesFromUri($receivedUri));
            $missingEpc = Epc::query()->create(Epc::materializeAttributesFromUri($missingUri));
            $this->epcId = (int) $receivedEpc->getKey();
            $extraEpcIds[] = (int) $missingEpc->getKey();

            foreach ([$receivedEpc, $missingEpc] as $epc) {
                $this->receiveAtSite($fromSite, $epc);
            }

            app(ConfirmTransferringScan::class)->handle($session, $receivedUri);
            app(ConfirmTransferringScan::class)->handle($session->fresh(), $missingUri);

            $shipped = app(CompleteTransferringSession::class)->handle($session->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $receiving = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $receivingSessionId = (int) $receiving->getKey();

            $barcode = '(01)'.$receivedEpc->gtin14.'(21)'.$receivedEpc->serial_number;
            app(ConfirmReceivingScan::class)->handle($receiving->fresh(), $barcode);

            $shipped->forceFill([
                'status' => 'completed',
                'received_count' => 1,
                'received_at' => now(),
                'completed_at' => now(),
            ])->save();

            $receiving->refresh();
            $this->assertNotSame('completed', $receiving->status);
            $this->assertSame(
                1,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $receiving->getKey())
                    ->where('status', 'expected')
                    ->count(),
            );

            app(CompleteReceivingSession::class)->handle($receiving->fresh());

            $shipped->refresh();
            $this->assertNull($shipped->receive_events_generated_at);
            $this->assertSame(
                0,
                EpcisEvent::query()
                    ->where('document_id', $this->transferDocumentId)
                    ->where('biz_step', 'urn:epcglobal:cbv:bizstep:receiving')
                    ->count(),
            );
        } finally {
            if (tenancy()->initialized) {
                if ($receivingSessionId !== null) {
                    ReceivingScanLine::query()->where('receiving_session_id', $receivingSessionId)->delete();
                    ReceivingSession::query()->whereKey($receivingSessionId)->delete();
                }
                foreach ($extraEpcIds as $extraEpcId) {
                    DB::table('event_epcs')->where('epc_id', $extraEpcId)->delete();
                    if (Schema::hasTable('document_epcs')) {
                        DB::table('document_epcs')->where('epc_id', $extraEpcId)->delete();
                    }
                    TransferringScanLine::query()->where('epc_id', $extraEpcId)->delete();
                    Epc::query()->whereKey($extraEpcId)->delete();
                }
            }
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function short_close_transfer_receive_authors_partial_receive_and_recomputes_received_count(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        /** @var list<int> $extraEpcIds */
        $extraEpcIds = [];
        $receivingSessionId = null;
        $suffix = (string) random_int(10000000, 99999999);
        $receivedUri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.SC'.$suffix;
        $missingUri = 'urn:epc:id:sgtin:030116.3'.substr((string) ($suffix + 1), 0, 6).'.SM'.($suffix + 1);

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $receivedEpc = Epc::query()->create(Epc::materializeAttributesFromUri($receivedUri));
            $missingEpc = Epc::query()->create(Epc::materializeAttributesFromUri($missingUri));
            $this->epcId = (int) $receivedEpc->getKey();
            $extraEpcIds[] = (int) $missingEpc->getKey();

            foreach ([$receivedEpc, $missingEpc] as $epc) {
                $this->receiveAtSite($fromSite, $epc);
            }

            app(ConfirmTransferringScan::class)->handle($session, $receivedUri);
            app(ConfirmTransferringScan::class)->handle($session->fresh(), $missingUri);

            $shipped = app(CompleteTransferringSession::class)->handle($session->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $receiving = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $receivingSessionId = (int) $receiving->getKey();

            $barcode = '(01)'.$receivedEpc->gtin14.'(21)'.$receivedEpc->serial_number;
            app(ConfirmReceivingScan::class)->handle($receiving->fresh(), $barcode);

            $shipped->forceFill([
                'status' => 'completed',
                'received_count' => 99,
                'received_at' => now(),
                'completed_at' => now(),
            ])->save();

            $receiving->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            app(CompleteReceivingSession::class)->handle($receiving->fresh());

            $shipped->refresh();
            $this->assertSame(1, (int) $shipped->received_count);
            $this->assertNotNull($shipped->receive_events_generated_at);
            $this->assertSame(
                1,
                EpcisEvent::query()
                    ->where('document_id', $this->transferDocumentId)
                    ->where('biz_step', 'urn:epcglobal:cbv:bizstep:receiving')
                    ->count(),
            );
        } finally {
            if (tenancy()->initialized) {
                if ($receivingSessionId !== null) {
                    ReceivingScanLine::query()->where('receiving_session_id', $receivingSessionId)->delete();
                    ReceivingSession::query()->whereKey($receivingSessionId)->delete();
                }
                foreach ($extraEpcIds as $extraEpcId) {
                    DB::table('event_epcs')->where('epc_id', $extraEpcId)->delete();
                    if (Schema::hasTable('document_epcs')) {
                        DB::table('document_epcs')->where('epc_id', $extraEpcId)->delete();
                    }
                    TransferringScanLine::query()->where('epc_id', $extraEpcId)->delete();
                    Epc::query()->whereKey($extraEpcId)->delete();
                }
            }
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function transfer_receive_scan_recomputes_received_count_from_received_lines(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $receivingSessionId = null;
        $suffix = (string) random_int(10000000, 99999999);
        $receivedUri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.RC'.$suffix;

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $receivedEpc = Epc::query()->create(Epc::materializeAttributesFromUri($receivedUri));
            $this->epcId = (int) $receivedEpc->getKey();

            $this->receiveAtSite($fromSite, $receivedEpc);

            app(ConfirmTransferringScan::class)->handle($session, $receivedUri);

            $shipped = app(CompleteTransferringSession::class)->handle($session->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $receiving = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $receivingSessionId = (int) $receiving->getKey();

            $shipped->forceFill(['received_count' => 99])->save();

            $barcode = '(01)'.$receivedEpc->gtin14.'(21)'.$receivedEpc->serial_number;
            app(ConfirmReceivingScan::class)->handle($receiving->fresh(), $barcode);

            $shipped->refresh();
            $this->assertSame(1, (int) $shipped->received_count);
            $this->assertNotNull($shipped->receive_events_generated_at);
        } finally {
            if (tenancy()->initialized) {
                if ($receivingSessionId !== null) {
                    ReceivingScanLine::query()->where('receiving_session_id', $receivingSessionId)->delete();
                    ReceivingSession::query()->whereKey($receivingSessionId)->delete();
                }
            }
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function reconcile_transfer_receive_defers_transfer_completion_until_epcis(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $transfer = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $transfer->getKey();

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.RC'.$suffix;

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($transfer, $uri);
            $shipped = app(CompleteTransferringSession::class)->handle($transfer->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            app(OpenTransferReceivingSession::class)->handle($shipped->fresh());

            $receive = app(ConfirmTransferringReceiveScan::class)->handle(
                $shipped->fresh(),
                $uri,
                generateReceiveEvents: false,
                markTransferCompleted: false,
            );
            $this->assertTrue($receive['ok']);
            $this->assertTrue($receive['session_completed']);

            $shipped->refresh();
            $this->assertSame('in_transit', $shipped->status);
            $this->assertSame(1, (int) $shipped->received_count);
            $this->assertNull($shipped->receive_events_generated_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function generate_transfer_epcis_fails_closed_when_confirmed_epc_under_hold(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $suffix = (string) random_int(10000000, 99999999);
        $epcUri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.HQ'.$suffix;

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($epcUri));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($session, $epcUri);

            $session->forceFill([
                'status' => 'in_transit',
                'shipped_at' => now(),
            ])->save();

            app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [$this->epcId],
                reason: 'Hold before transfer ship EPCIS authoring',
            );

            try {
                app(GenerateTransferringEpcisEvents::class)->handle($session->fresh());
                $this->fail('Expected quarantine hold to block transfer ship EPCIS authoring.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('quarantine', strtolower($exception->getMessage()));
            }

            $this->assertNull($session->fresh()->transfer_events_generated_at);
            $this->assertSame(
                0,
                EpcisDocument::query()
                    ->where('notes', 'like', '%transferring session #'.$this->sessionId.'%')
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function generate_transfer_receive_epcis_fails_closed_when_received_epc_under_hold(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $suffix = (string) random_int(10000000, 99999999);
        $epcUri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.HQ'.$suffix;

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($epcUri));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($session, $epcUri);
            $shipped = app(CompleteTransferringSession::class)->handle($session->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            app(ConfirmTransferringReceiveScan::class)->handle(
                $shipped->fresh(),
                $epcUri,
                generateReceiveEvents: false,
            );

            $shipped->refresh();
            $this->assertSame('completed', $shipped->status);
            $this->assertNull($shipped->receive_events_generated_at);

            app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [$this->epcId],
                reason: 'Hold after receive marks',
            );

            try {
                app(GenerateTransferringReceiveEpcisEvents::class)->handle($shipped->fresh());
                $this->fail('Expected quarantine hold to block transfer-receive EPCIS authoring.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('quarantine', strtolower($exception->getMessage()));
            }

            $this->assertNull($shipped->fresh()->receive_events_generated_at);
            $this->assertSame(
                0,
                EpcisEvent::query()
                    ->where('document_id', $this->transferDocumentId)
                    ->where('biz_step', 'urn:epcglobal:cbv:bizstep:receiving')
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function generate_transfer_epcis_fails_closed_when_confirmed_epc_destroyed_after_complete_gate(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $suffix = (string) random_int(10000000, 99999999);
        $epcUri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.DS'.$suffix;

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($epcUri));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($session, $epcUri);

            $session->forceFill([
                'status' => 'in_transit',
                'shipped_at' => now(),
            ])->save();

            $this->authorTerminalEvent($fromSite, $epc, 'urn:epcglobal:cbv:disp:destroyed');

            try {
                app(GenerateTransferringEpcisEvents::class)->handle($session->fresh());
                $this->fail('Expected destroyed unit to block transfer ship EPCIS authoring.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('destroyed', strtolower($exception->getMessage()));
            }

            $this->assertNull($session->fresh()->transfer_events_generated_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function generate_transfer_epcis_fails_closed_when_confirmed_epc_decommissioned_after_complete_gate(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $suffix = (string) random_int(10000000, 99999999);
        $epcUri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.DC'.$suffix;

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($epcUri));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($session, $epcUri);

            $session->forceFill([
                'status' => 'in_transit',
                'shipped_at' => now(),
            ])->save();

            $this->authorTerminalEvent($fromSite, $epc, 'urn:epcglobal:cbv:disp:inactive');

            try {
                app(GenerateTransferringEpcisEvents::class)->handle($session->fresh());
                $this->fail('Expected decommissioned unit to block transfer ship EPCIS authoring.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('inactive', strtolower($exception->getMessage()));
            }

            $this->assertNull($session->fresh()->transfer_events_generated_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function generate_transfer_receive_epcis_fails_closed_when_received_epc_destroyed_after_complete_gate(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $suffix = (string) random_int(10000000, 99999999);
        $epcUri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.RD'.$suffix;

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($epcUri));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($session, $epcUri);
            $shipped = app(CompleteTransferringSession::class)->handle($session->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            app(ConfirmTransferringReceiveScan::class)->handle(
                $shipped->fresh(),
                $epcUri,
                generateReceiveEvents: false,
            );

            $this->authorTerminalEvent($toSite, $epc, 'urn:epcglobal:cbv:disp:destroyed');

            try {
                app(GenerateTransferringReceiveEpcisEvents::class)->handle($shipped->fresh());
                $this->fail('Expected destroyed unit to block transfer-receive EPCIS authoring.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('destroyed', strtolower($exception->getMessage()));
            }

            $this->assertNull($shipped->fresh()->receive_events_generated_at);
            $this->assertSame(
                0,
                EpcisEvent::query()
                    ->where('document_id', $this->transferDocumentId)
                    ->where('biz_step', 'urn:epcglobal:cbv:bizstep:receiving')
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function generate_transfer_receive_epcis_fails_closed_when_received_epc_decommissioned_after_complete_gate(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $suffix = (string) random_int(10000000, 99999999);
        $epcUri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.RC'.$suffix;

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($epcUri));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($session, $epcUri);
            $shipped = app(CompleteTransferringSession::class)->handle($session->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            app(ConfirmTransferringReceiveScan::class)->handle(
                $shipped->fresh(),
                $epcUri,
                generateReceiveEvents: false,
            );

            $this->authorTerminalEvent($toSite, $epc, 'urn:epcglobal:cbv:disp:inactive');

            try {
                app(GenerateTransferringReceiveEpcisEvents::class)->handle($shipped->fresh());
                $this->fail('Expected decommissioned unit to block transfer-receive EPCIS authoring.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('inactive', strtolower($exception->getMessage()));
            }

            $this->assertNull($shipped->fresh()->receive_events_generated_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function transfer_receive_epcis_failure_reverts_completed_sessions(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $receivingSessionId = null;
        $suffix = (string) random_int(10000000, 99999999);
        $epcUri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.TR'.$suffix;

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($epcUri));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($session, $epcUri);
            $shipped = app(CompleteTransferringSession::class)->handle($session->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $receiving = app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
            $receivingSessionId = (int) $receiving->getKey();

            $barcode = '(01)'.$epc->gtin14.'(21)'.$epc->serial_number;

            $toSite->forceFill(['gln' => null])->save();

            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->save();

            $result = app(ConfirmReceivingScan::class)->handle($receiving->fresh(), $barcode);

            $this->assertTrue($result['ok']);
            $this->assertFalse($result['session_completed']);
            $this->assertArrayHasKey('completion_error', $result);
            $this->assertStringContainsString('receiving epcis could not be authored', strtolower((string) $result['completion_error']));

            $receiving->refresh();
            $shipped->refresh();

            $this->assertSame('in_progress', $receiving->status);
            $this->assertNull($receiving->completed_at);
            $this->assertSame('in_transit', $shipped->status);
            $this->assertNull($shipped->completed_at);
            $this->assertNull($shipped->receive_events_generated_at);
            $this->assertSame(
                'received',
                TransferringScanLine::query()
                    ->where('transferring_session_id', $shipped->getKey())
                    ->where('epc_id', $this->epcId)
                    ->value('status'),
            );
        } finally {
            if (tenancy()->initialized) {
                if ($receivingSessionId !== null) {
                    ReceivingScanLine::query()->where('receiving_session_id', $receivingSessionId)->delete();
                    ReceivingSession::query()->whereKey($receivingSessionId)->delete();
                }
            }
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function destination_only_user_can_complete_the_transfer_receive(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $shipped = $this->shipOneEpc($fromSite, $toSite);

            $document = EpcisDocument::query()->findOrFail($this->transferDocumentId);

            // A destination-floor operator has no site_user row for the origin warehouse,
            // which is the whole point: closing the receive must not require one.
            $this->actingAs($this->createUserWithSites([(int) $toSite->getKey()]));

            $receive = app(ConfirmTransferringReceiveScan::class)->handle(
                $shipped->fresh(),
                self::EPC_URI,
                generateReceiveEvents: true,
            );

            $this->assertTrue($receive['ok']);
            $this->assertTrue($receive['session_completed']);
            $this->assertSame('completed', $receive['effect']);

            $completed = TransferringSession::query()->findOrFail($this->sessionId);
            $this->assertSame('completed', $completed->status);
            $this->assertNotNull($completed->receive_events_generated_at);

            $receiving = EpcisEvent::query()
                ->where('document_id', $this->transferDocumentId)
                ->where('biz_step', 'urn:epcglobal:cbv:bizstep:receiving')
                ->firstOrFail();
            $this->assertSame($this->toGln, $receiving->read_point_gln);

            // The reissued shipping event still names the origin this operator cannot see.
            $document->refresh();
            $receiveXml = (string) Storage::disk($document->payload_disk)->get($document->payload_path);
            $this->assertStringContainsString(
                '<id>'.$this->expectedSglnUrn($tenant, $this->fromGln).'</id>',
                $receiveXml,
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * The seam requirement behind the test above, pinned directly: the two resolvers
     * answer different questions, and only the operator-scoped one may refuse.
     */
    #[Test]
    public function origin_site_identity_resolves_for_a_destination_only_user(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $fromSiteId = (int) $fromSite->getKey();

            $this->actingAs($this->createUserWithSites([(int) $toSite->getKey()]));

            try {
                app(ResolveShipFromSite::class)->locationGlnsForAuthoring($fromSiteId);
                $this->fail('ResolveShipFromSite must keep asserting site access for the acting operator.');
            } catch (AuthorizationException) {
                // Expected: this operator may not author *from* the origin warehouse.
            }

            // Naming a location the record already fixed is not the same act, so it stands.
            $resolved = app(ResolveSiteLocationGlns::class)->handle($fromSiteId, 'Transfer origin site');

            $this->assertSame($fromSiteId, $resolved['site_id']);
            $this->assertSame($this->fromGln, $resolved['gln']);
            $this->assertSame($this->expectedSglnUrn($tenant, $this->fromGln), $resolved['sgln_urn']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function transfer_receive_reuses_the_ship_leg_sgln_when_the_origin_site_gln_changes(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $shipped = $this->shipOneEpc($fromSite, $toSite);

            $document = EpcisDocument::query()->findOrFail($this->transferDocumentId);
            $shippedUrn = $this->expectedSglnUrn($tenant, $this->fromGln);

            // The origin site is re-keyed while the shipment is in transit. The in_transit
            // event is already with the partner under the SGLN above, so reissuing it in
            // the receive document must not move that eventID to another location.
            $rekeyedGln = $this->uniqueGln();
            $fromSite->forceFill(['gln' => $rekeyedGln])->save();
            $rekeyedUrn = $this->expectedSglnUrn($tenant, $rekeyedGln);
            $this->assertNotSame($shippedUrn, $rekeyedUrn);

            app(ConfirmTransferringReceiveScan::class)->handle(
                $shipped->fresh(),
                self::EPC_URI,
                generateReceiveEvents: true,
            );

            $document->refresh();
            $receiveXml = (string) Storage::disk($document->payload_disk)->get($document->payload_path);

            $this->assertSame(
                2,
                substr_count($receiveXml, '<id>'.$shippedUrn.'</id>'),
                'The reissued shipping event keeps the readPoint and bizLocation it shipped under.',
            );
            $this->assertStringNotContainsString('<id>'.$rekeyedUrn.'</id>', $receiveXml);

            $shipping = EpcisEvent::query()
                ->where('document_id', $this->transferDocumentId)
                ->where('biz_step', 'urn:epcglobal:cbv:bizstep:shipping')
                ->firstOrFail();
            $this->assertSame($this->fromGln, $shipping->read_point_gln);

            // The receiving event is authored at the destination as it stands now.
            $this->assertStringContainsString(
                '<id>'.$this->expectedSglnUrn($tenant, $this->toGln).'</id>',
                $receiveXml,
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function transfer_xml_uses_tenant_company_prefix_for_site_sgln(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        $this->priorCompanyPrefix = $tenant->company_prefix;
        $this->priorTenantGln = $tenant->gln;

        try {
            $fromGln = '0366159000010';
            $toGln = '0366159000026';
            Site::query()->whereIn('gln', [$fromGln, $toGln])->delete();

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => $fromGln,
                'company_prefix' => '036615',
            ]);
            tenancy()->end();
            tenancy()->initialize($tenant->fresh());

            $this->fromGln = $fromGln;
            $this->toGln = $toGln;

            $fromSite = Site::query()->create([
                'name' => 'Transfer SGLN From',
                'gln' => $fromGln,
                'is_active' => true,
                'is_headquarters' => true,
                'trading_partner_id' => null,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $fromSite->getKey();

            $toSite = Site::query()->create([
                'name' => 'Transfer SGLN To',
                'gln' => $toGln,
                'is_active' => true,
                'is_headquarters' => false,
                'trading_partner_id' => null,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $toSite->getKey();

            $settings = TenantSettings::forTenant($tenant);
            $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
            $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
            $settings->setDefaultShipFromSiteId((int) $fromSite->getKey());
            $settings->setDefaultReceiveSiteId((int) $toSite->getKey());
            $tenant->save();

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($session, self::EPC_URI);
            $shipped = app(CompleteTransferringSession::class)->handle($session->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $document = EpcisDocument::query()->findOrFail($shipped->transfer_epcis_document_id);
            $expectedFromUrn = 'urn:epc:id:sgln:036615.900001.0';
            $this->assertSame($expectedFromUrn, Sgln::toUrn($fromGln, 6));

            $payload = Storage::disk($document->payload_disk)->get($document->payload_path);
            $this->assertIsString($payload);
            $this->assertStringContainsString('<readPoint>', $payload);
            $this->assertStringContainsString('<bizLocation>', $payload);
            $this->assertStringContainsString('<id>'.$expectedFromUrn.'</id>', $payload);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function cannot_confirm_same_epc_on_two_open_transfer_sessions(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $sessionA = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $sessionA->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            $first = app(ConfirmTransferringScan::class)->handle($sessionA, self::EPC_URI);
            $this->assertTrue($first['ok']);
            $this->assertSame('confirmed', $first['effect']);

            $sessionB = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );

            $second = app(ConfirmTransferringScan::class)->handle($sessionB, self::EPC_URI);
            $this->assertFalse($second['ok']);
            $this->assertSame('double_transfer', $second['effect']);
            $this->assertSame('Already on another open transfer session.', $second['message']);

            // Cleanup second session (first cleaned via sessionId).
            TransferringScanLine::query()
                ->where('transferring_session_id', $sessionB->getKey())
                ->delete();
            TransferringSession::query()->whereKey($sessionB->getKey())->delete();
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function cannot_confirm_epc_already_on_open_receive_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.TR'.$suffix;
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();

            $receiveSession = app(OpenScanFirstReceivingSession::class)->handle((int) $fromSite->getKey());
            $received = app(ConfirmReceivingScan::class)->handle($receiveSession, $uri);
            $this->assertTrue($received['ok'], $received['message']);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $result = app(ConfirmTransferringScan::class)->handle($session, $uri);

            $this->assertFalse($result['ok']);
            $this->assertSame('on_open_receive', $result['effect']);
            $this->assertSame('Already confirmed on an open receive session.', $result['message']);
            $this->assertSame(0, (int) $session->fresh()->confirmed_count);

            ReceivingScanLine::query()->where('receiving_session_id', $receiveSession->getKey())->delete();
            ReceivingSession::query()->whereKey($receiveSession->getKey())->delete();
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function cannot_confirm_epc_already_on_open_ship_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::SHIP_BLOCK_EPC_URI));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            $shipSession = OutboundShippingSession::query()->create([
                'site_id' => $fromSite->getKey(),
                'status' => 'open',
                'expected_count' => 0,
                'confirmed_count' => 1,
                'dscsa_affirm' => false,
                'opened_at' => now(),
            ]);
            $this->shipSessionId = (int) $shipSession->getKey();

            OutboundShippingScanLine::query()->create([
                'outbound_shipping_session_id' => $shipSession->getKey(),
                'epc_id' => $epc->getKey(),
                'line_role' => 'parent',
                'status' => 'confirmed',
                'scan_raw' => self::SHIP_BLOCK_EPC_URI,
                'confirmed_at' => now(),
            ]);

            $result = app(ConfirmTransferringScan::class)->handle($session, self::SHIP_BLOCK_EPC_URI);

            $this->assertFalse($result['ok']);
            $this->assertSame('on_open_ship', $result['effect']);
            $this->assertSame('Already on another open ship order.', $result['message']);
            $this->assertSame(0, (int) $session->fresh()->confirmed_count);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function cannot_receive_while_session_is_open(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($session, self::EPC_URI);

            $receive = app(ConfirmTransferringReceiveScan::class)->handle($session->fresh(), self::EPC_URI);

            $this->assertFalse($receive['ok']);
            $this->assertSame('session_not_in_transit', $receive['effect']);
            $this->assertSame('open', $session->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function cannot_author_transfer_epcis_with_no_confirmed_scan_lines(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $session->forceFill([
                'status' => 'in_transit',
                'shipped_at' => now(),
                'confirmed_count' => 1,
            ])->save();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('no confirmed scan lines');

            app(GenerateTransferringEpcisEvents::class)->handle($session->fresh());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function cannot_ship_with_zero_confirmed_scans(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Cannot ship transferring session with no confirmed scans.');

            app(CompleteTransferringSession::class)->handle($session);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function ship_epcis_failure_reverts_session_to_open_and_allows_retry(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $restorePrefix = null;

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($session, self::EPC_URI);

            $settings = TenantSettings::forTenant($tenant);
            $restorePrefix = $settings->companyPrefix();
            $settings->setCompanyPrefix(null);
            $tenant->save();
            $fromSite->forceFill(['sgln' => null])->save();
            $toSite->forceFill(['sgln' => null])->save();
            $this->assertNull($fromSite->fresh()->sgln);
            $this->assertNull($toSite->fresh()->sgln);

            try {
                app(CompleteTransferringSession::class)->handle($session->fresh());
                $this->fail('Expected DomainException when transferring EPCIS cannot be authored.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('could not be authored', $e->getMessage());
            }

            $session = $session->fresh();
            $this->assertSame('open', $session->status);
            $this->assertNull($session->shipped_at);
            $this->assertNull($session->transfer_events_generated_at);
            $this->assertNull($session->transfer_epcis_document_id);

            $settings->setCompanyPrefix($restorePrefix);
            $tenant->save();
            $restorePrefix = null;

            $shipped = app(CompleteTransferringSession::class)->handle($session->fresh());
            $this->assertSame('in_transit', $shipped->status);
            $this->assertNotNull($shipped->shipped_at);
            $this->assertNotNull($shipped->transfer_events_generated_at);
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $document = EpcisDocument::query()->findOrFail($this->transferDocumentId);
            $this->shipPayloadDisk = (string) $document->payload_disk;
            $this->shipPayloadPath = (string) $document->payload_path;
        } finally {
            if ($restorePrefix !== null && tenancy()->initialized) {
                TenantSettings::forTenant(tenant())->setCompanyPrefix($restorePrefix);
                tenant()->save();
            }

            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function generate_failure_does_not_revert_when_transfer_events_already_exist(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($session, self::EPC_URI);

            $session->forceFill([
                'status' => 'in_transit',
                'shipped_at' => now(),
                'transfer_events_generated_at' => now(),
            ])->save();

            $action = app(CompleteTransferringSession::class);
            $method = new ReflectionMethod($action, 'revertIncompleteShip');
            $method->invoke($action, $session);

            $session = $session->fresh();
            $this->assertSame('in_transit', $session->status);
            $this->assertNotNull($session->shipped_at);
            $this->assertNotNull($session->transfer_events_generated_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function ship_retries_when_stuck_in_transit_without_epcis(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            app(ConfirmTransferringScan::class)->handle($session, self::EPC_URI);

            // Simulate crash after status flip, before authoring.
            $session->forceFill([
                'status' => 'in_transit',
                'shipped_at' => now(),
            ])->save();

            $shipped = app(CompleteTransferringSession::class)->handle($session->fresh());
            $this->assertSame('in_transit', $shipped->status);
            $this->assertNotNull($shipped->transfer_events_generated_at);
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $document = EpcisDocument::query()->findOrFail($this->transferDocumentId);
            $this->shipPayloadDisk = (string) $document->payload_disk;
            $this->shipPayloadPath = (string) $document->payload_path;
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function destination_only_user_cannot_confirm_origin_transfer_scan(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
            $this->epcId = (int) $epc->getKey();
            $this->receiveAtSite($fromSite, $epc);

            $this->actingAs($this->createUserWithSites([(int) $toSite->getKey()]));

            $this->expectException(AuthorizationException::class);
            app(ConfirmTransferringScan::class)->handle($session, self::EPC_URI);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function origin_only_user_cannot_open_transfer_receiving_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $shipped = $this->shipOneEpc($fromSite, $toSite);

            $this->actingAs($this->createUserWithSites([(int) $fromSite->getKey()]));

            $this->expectException(AuthorizationException::class);
            app(OpenTransferReceivingSession::class)->handle($shipped->fresh());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function receive_rejects_quarantined_epc(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $shipped = $this->shipOneEpc($fromSite, $toSite);

            app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [$this->epcId],
                reason: 'Transfer receive quarantine gate',
            );

            $receive = app(ConfirmTransferringReceiveScan::class)->handle(
                $shipped->fresh(),
                self::EPC_URI,
            );

            $this->assertFalse($receive['ok']);
            $this->assertSame('quarantined', $receive['effect']);
            $this->assertSame('confirmed', TransferringScanLine::query()
                ->where('transferring_session_id', $this->sessionId)
                ->where('epc_id', $this->epcId)
                ->value('status'));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function generate_transfer_epcis_is_idempotent_under_repeat_calls(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $shipped = $this->shipOneEpc($fromSite, $toSite);

            $first = app(GenerateTransferringEpcisEvents::class)->handle($shipped->fresh());
            $second = app(GenerateTransferringEpcisEvents::class)->handle($shipped->fresh());

            $this->assertFalse($first['generated']);
            $this->assertFalse($second['generated']);
            $this->assertSame(1, EpcisDocument::query()
                ->where('notes', 'like', '%transferring session #'.$this->sessionId.'%')
                ->count());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function cannot_transfer_to_the_same_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite] = $this->createTransferSites($tenant);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Cannot transfer to the same site.');

            app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $fromSite->getKey(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createTransferSites(Tenant $tenant): array
    {
        $this->fromGln = $this->uniqueGln();
        $this->toGln = $this->uniqueGln();

        $fromSite = Site::query()->create([
            'name' => 'Transfer From '.Str::random(6),
            'gln' => $this->fromGln,
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $fromSite->getKey();

        $toSite = Site::query()->create([
            'name' => 'Transfer To '.Str::random(6),
            'gln' => $this->toGln,
            'is_active' => true,
            'is_headquarters' => false,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $toSite->getKey();

        $settings = TenantSettings::forTenant($tenant);
        $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        $settings->setDefaultShipFromSiteId((int) $fromSite->getKey());
        $settings->setDefaultReceiveSiteId((int) $toSite->getKey());
        $tenant->save();

        return [$fromSite, $toSite];
    }

    /**
     * Take a session all the way to in_transit with one confirmed EPC, unauthenticated:
     * the ship leg belongs to the origin operator, whoever later closes the receive.
     */
    private function shipOneEpc(Site $fromSite, Site $toSite): TransferringSession
    {
        $session = app(OpenTransferringSession::class)->handle(
            fromSiteId: (int) $fromSite->getKey(),
            toSiteId: (int) $toSite->getKey(),
        );
        $this->sessionId = (int) $session->getKey();

        $epc = Epc::query()->create(Epc::materializeAttributesFromUri(self::EPC_URI));
        $this->epcId = (int) $epc->getKey();
        $this->receiveAtSite($fromSite, $epc);

        app(ConfirmTransferringScan::class)->handle($session, self::EPC_URI);

        $shipped = app(CompleteTransferringSession::class)->handle($session->fresh());
        $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

        $document = EpcisDocument::query()->findOrFail($this->transferDocumentId);
        $this->shipPayloadDisk = (string) $document->payload_disk;
        $this->shipPayloadPath = (string) $document->payload_path;

        return $shipped;
    }

    private function expectedSglnUrn(Tenant $tenant, ?string $gln): string
    {
        $prefix = TenantSettings::forTenant($tenant)->companyPrefix();
        $this->assertNotNull($prefix, 'The demo tenant needs a GS1 company prefix to author SGLNs.');

        $urn = Sgln::toUrn($gln, strlen($prefix));
        $this->assertNotNull($urn);

        return $urn;
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function createUserWithSites(array $siteIds): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
        $user->syncSites($siteIds);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    /**
     * Transferring requires tenant custody, which comes from a receiving event at one of
     * our GLNs — the same ObjectEvent GenerateReceivingEpcisEvents authors on receipt.
     */
    private function receiveAtSite(Site $site, Epc $epc): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'transfer-custody-receipt.xml',
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

    private function authorTerminalEvent(Site $site, Epc $epc, string $disposition): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'transfer-terminal-'.Str::random(6).'.xml',
            'notes' => 'Terminal disposition for transfer EPCIS generator custody recheck test.',
        ]);
        $this->custodyDocumentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => now(),
            'record_time' => now(),
            'event_timezone_offset' => '+00:00',
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:decommissioning',
            'disposition' => $disposition,
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

    /**
     * ResolveShipFromSite refuses to author from a site whose GLN falls outside the
     * organization's own GS1 company prefix, so transfer sites must be built on it.
     */
    private function uniqueGln(): string
    {
        $prefix = TenantSettings::forTenant(tenant())->companyPrefix() ?: '03';
        $fill = max(1, 12 - strlen($prefix));

        do {
            $body = substr($prefix.str_pad((string) random_int(0, (int) str_repeat('9', $fill)), $fill, '0', STR_PAD_LEFT), 0, 12);
            $gln = $body.Gtin::checkDigit($body);
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

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->custodyEventIds !== []) {
                DB::table('event_epcs')->whereIn('event_id', $this->custodyEventIds)->delete();
                EpcisEvent::query()->whereIn('id', $this->custodyEventIds)->delete();
                $this->custodyEventIds = [];
            }

            if ($this->custodyDocumentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->custodyDocumentIds)->delete();
                $this->custodyDocumentIds = [];
            }

            if ($this->shipPayloadPath !== null) {
                Storage::disk($this->shipPayloadDisk ?? 'local')->delete($this->shipPayloadPath);
                $this->shipPayloadDisk = null;
                $this->shipPayloadPath = null;
            }

            if ($this->transferDocumentId !== null) {
                $document = EpcisDocument::query()->find($this->transferDocumentId);
                if ($document !== null && filled($document->payload_path)) {
                    Storage::disk($document->payload_disk)->delete($document->payload_path);
                }
                EpcisDocument::query()->whereKey($this->transferDocumentId)->delete();
                $this->transferDocumentId = null;
            }

            if ($this->sessionId !== null) {
                TransferringSession::query()->whereKey($this->sessionId)->delete();
                $this->sessionId = null;
            }

            if ($this->shipSessionId !== null) {
                OutboundShippingSession::query()->whereKey($this->shipSessionId)->delete();
                $this->shipSessionId = null;
            }

            if ($this->epcId !== null) {
                \App\Models\Quarantine\QuarantineHold::query()->where('epc_id', $this->epcId)->delete();
                DB::table('exception_epcs')->where('epc_id', $this->epcId)->delete();
                DB::table('event_epcs')->where('epc_id', $this->epcId)->delete();
                if (Schema::hasTable('document_epcs')) {
                    DB::table('document_epcs')->where('epc_id', $this->epcId)->delete();
                }
                OutboundShippingScanLine::query()->where('epc_id', $this->epcId)->delete();
                TransferringScanLine::query()->where('epc_id', $this->epcId)->delete();
                Epc::query()->whereKey($this->epcId)->delete();
                $this->epcId = null;
            }

            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
                $this->userIds = [];
            }

            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
                $this->siteIds = [];
            }

            $settings = TenantSettings::forTenant($tenant);
            $settings->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
            $settings->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
            $tenant->save();
            $this->priorDefaultShipFromSiteId = null;
            $this->priorDefaultReceiveSiteId = null;

            if ($this->priorCompanyPrefix !== null || $this->priorTenantGln !== null) {
                $restored = Tenant::query()->find(self::DEMO2_TENANT_ID);
                if ($restored !== null) {
                    $restored->forceFill([
                        'company_prefix' => $this->priorCompanyPrefix,
                        'gln' => $this->priorTenantGln,
                    ])->save();
                }
                $this->priorCompanyPrefix = null;
                $this->priorTenantGln = null;
            }

            auth()->logout();
            tenancy()->end();
        }
    }
}
