<?php

namespace Tests\Feature\Demo;

use App\Actions\Demo\SeedMasterData;
use App\Actions\Demo\SeedOperationalChoreography;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\OutboundConnection;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\SsccLabelBatch;
use App\Models\Tenant;
use App\Models\Transferring\TransferringSession;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantOnboarding;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeedOperationalChoreographyTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'collection']);
    }

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $receivingSessionIds = [];

    /** @var list<int> */
    private array $shippingSessionIds = [];

    /** @var list<int> */
    private array $transferringSessionIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $packBatchIds = [];

    #[Test]
    public function hierarchy_unpack_and_pack_path_is_idempotent_without_breaking_ship_demo(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);

        try {
            Http::fake([
                'https://example.com/epcis-inbound' => Http::response('OK', 202),
                'https://l3.example.test/*' => Http::response('OK', 202),
            ]);

            $this->purgeStaleDemoChoreography();

            app(SeedMasterData::class)->handle();

            $first = app(SeedOperationalChoreography::class)->handle(
                includeUnpack: true,
                includePack: true,
            );
            $second = app(SeedOperationalChoreography::class)->handle(
                includeUnpack: true,
                includePack: true,
            );

            $this->assertTrue($first['ship_completed']);
            $this->assertTrue($first['unpack_completed'], $first['unpack_deferred_reason'] ?? '');
            $this->assertTrue($first['pack_completed'], $first['pack_deferred_reason'] ?? '');
            $this->assertNotNull($first['hierarchy_receive_session_id']);
            $this->assertNotNull($first['pack_batch_id']);

            $this->assertSame($first['hierarchy_receive_session_id'], $second['hierarchy_receive_session_id']);
            $this->assertSame($first['pack_batch_id'], $second['pack_batch_id']);
            $this->assertFalse($second['unpack_created']);
            $this->assertFalse($second['pack_created']);

            $hierarchyReceive = ReceivingSession::query()->findOrFail($first['hierarchy_receive_session_id']);
            $this->trackReceiveArtifacts($hierarchyReceive);

            $packBatch = SsccLabelBatch::query()->findOrFail($first['pack_batch_id']);
            $this->packBatchIds[] = (int) $packBatch->getKey();
            $this->assertSame(SeedOperationalChoreography::DEMO_PACK_BATCH_NOTES, $packBatch->notes);

            $this->assertTrue(
                OutboundShippingSession::query()
                    ->where('asn_number', SeedOperationalChoreography::DEMO_SHIP_ASN)
                    ->where('status', 'completed')
                    ->exists(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function hierarchy_unpack_and_return_authors_returning_epcis_for_loose_child(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);

        try {
            Http::fake([
                'https://example.com/epcis-inbound' => Http::response('OK', 202),
                'https://l3.example.test/*' => Http::response('OK', 202),
            ]);

            $this->purgeStaleDemoChoreography();

            app(SeedMasterData::class)->handle();

            $first = app(SeedOperationalChoreography::class)->handle(
                completeShip: false,
                includeUnpack: true,
                includeReturn: true,
            );
            $second = app(SeedOperationalChoreography::class)->handle(
                completeShip: false,
                includeUnpack: true,
                includeReturn: true,
            );

            $this->assertTrue($first['unpack_completed'], $first['unpack_deferred_reason'] ?? '');
            $this->assertTrue($first['return_completed'], $first['return_deferred_reason'] ?? '');
            $this->assertNotNull($first['return_document_id']);
            $this->assertSame($first['return_document_id'], $second['return_document_id']);
            $this->assertFalse($second['return_created']);

            $returnDocument = EpcisDocument::query()->findOrFail($first['return_document_id']);
            $this->documentIds[] = (int) $returnDocument->getKey();
            $this->assertSame(SeedOperationalChoreography::DEMO_RETURN_DOCUMENT_NOTES, $returnDocument->notes);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function return_only_authors_returning_epcis_for_sealed_hierarchy_sscc(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);

        try {
            Http::fake([
                'https://example.com/epcis-inbound' => Http::response('OK', 202),
                'https://l3.example.test/*' => Http::response('OK', 202),
            ]);

            $this->purgeStaleDemoChoreography();

            app(SeedMasterData::class)->handle();

            $result = app(SeedOperationalChoreography::class)->handle(
                completeShip: false,
                includeReturn: true,
            );

            $this->assertNotNull($result['hierarchy_receive_session_id']);
            $this->assertTrue($result['return_completed']);
            $this->assertNotNull($result['return_document_id']);

            $hierarchyReceive = ReceivingSession::query()->findOrFail($result['hierarchy_receive_session_id']);
            $this->trackReceiveArtifacts($hierarchyReceive);

            $returnDocument = EpcisDocument::query()->findOrFail($result['return_document_id']);
            $this->documentIds[] = (int) $returnDocument->getKey();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function seed_creates_completed_receive_and_ship_for_wholesaler_demo(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);

        try {
            Http::fake([
                'https://example.com/epcis-inbound' => Http::response('OK', 202),
                'https://l3.example.test/*' => Http::response('OK', 202),
            ]);

            $this->purgeStaleDemoChoreography();

            app(SeedMasterData::class)->handle();

            $first = app(SeedOperationalChoreography::class)->handle();
            $second = app(SeedOperationalChoreography::class)->handle();

            $receive = ReceivingSession::query()->findOrFail($first['receive_session_id']);
            $this->trackReceiveArtifacts($receive);

            $this->assertSame('completed', $receive->status);
            $this->assertNotNull($receive->site_id);
            $this->assertNotNull($receive->receiving_events_generated_at);

            $epcId = (int) Epc::query()
                ->where('epc_uri', SeedOperationalChoreography::DEMO_SSCC_URI)
                ->value('id');
            $this->assertGreaterThan(0, $epcId);
            $this->assertTrue(
                app(ShippableEpcsAtSite::class)->contains((int) $receive->site_id, $epcId)
                    || OutboundShippingSession::query()
                        ->where('asn_number', SeedOperationalChoreography::DEMO_SHIP_ASN)
                        ->where('status', 'completed')
                        ->exists(),
                'After seed, demo SSCC should be on hand or already shipped on the demo order.',
            );

            $onboarding = TenantOnboarding::forTenant($tenant->fresh());
            $byId = collect($onboarding->items())->keyBy('id');

            $this->assertTrue($byId['receive_proven']['done']);
            $this->assertTrue($first['ship_completed']);
            $this->assertNotNull($first['ship_session_id']);
            $this->assertTrue($byId['ship_proven']['done']);

            $ship = OutboundShippingSession::query()->findOrFail($first['ship_session_id']);
            $this->trackShipArtifacts($ship);

            $this->assertSame('completed', $ship->status);
            $this->assertNotNull($ship->epcis_document_id);
            $this->assertSame(SeedOperationalChoreography::DEMO_SHIP_ASN, $ship->asn_number);

            $this->assertSame($first['receive_session_id'], $second['receive_session_id']);
            $this->assertSame($first['ship_session_id'], $second['ship_session_id']);
            $this->assertFalse($second['receive_created']);
            $this->assertFalse($second['ship_created']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function receive_only_leaves_ship_order_for_a_live_demo_click(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);

        try {
            Http::fake([
                'https://example.com/epcis-inbound' => Http::response('OK', 202),
                'https://l3.example.test/*' => Http::response('OK', 202),
            ]);

            $this->purgeStaleDemoChoreography();

            app(SeedMasterData::class)->handle();

            $result = app(SeedOperationalChoreography::class)->handle(completeShip: false);

            $this->assertNotNull($result['receive_session_id']);
            $this->assertTrue($result['ship_deferred']);
            $this->assertNull($result['ship_session_id']);

            $onboarding = TenantOnboarding::forTenant($tenant->fresh());
            $byId = collect($onboarding->items())->keyBy('id');

            $this->assertTrue($byId['receive_proven']['done']);
            $this->assertFalse($byId['ship_proven']['done']);

            $receive = ReceivingSession::query()->findOrFail($result['receive_session_id']);
            $this->trackReceiveArtifacts($receive);

            $epcId = (int) Epc::query()
                ->where('epc_uri', SeedOperationalChoreography::DEMO_SSCC_URI)
                ->value('id');
            $this->assertTrue(
                app(ShippableEpcsAtSite::class)->contains((int) $receive->site_id, $epcId),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function transfer_path_seeds_completed_inter_site_transfer_without_breaking_ship_demo(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);

        try {
            Http::fake([
                'https://example.com/epcis-inbound' => Http::response('OK', 202),
                'https://l3.example.test/*' => Http::response('OK', 202),
            ]);

            $this->purgeStaleDemoChoreography();

            app(SeedMasterData::class)->handle();

            $first = app(SeedOperationalChoreography::class)->handle(includeTransfer: true);
            $second = app(SeedOperationalChoreography::class)->handle(includeTransfer: true);

            $this->assertTrue($first['ship_completed']);
            $this->assertNotNull($first['ship_session_id']);
            $this->assertTrue($first['transfer_completed']);
            $this->assertNotNull($first['transfer_session_id']);
            $this->assertNotNull($first['transfer_receive_session_id']);

            $this->assertSame($first['receive_session_id'], $second['receive_session_id']);
            $this->assertSame($first['ship_session_id'], $second['ship_session_id']);
            $this->assertSame($first['transfer_session_id'], $second['transfer_session_id']);
            $this->assertFalse($second['receive_created']);
            $this->assertFalse($second['ship_created']);
            $this->assertFalse($second['transfer_created']);

            $transfer = TransferringSession::query()->findOrFail($first['transfer_session_id']);
            $this->trackTransferArtifacts($transfer);

            $this->assertSame('completed', $transfer->status);
            $this->assertSame(
                SeedOperationalChoreography::DEMO_TRANSFER_SESSION_NOTES,
                $transfer->notes,
            );
            $this->assertNotNull($transfer->transfer_events_generated_at);
            $this->assertNotNull($transfer->receive_events_generated_at);

            $branch = Site::query()->where('code', SeedOperationalChoreography::DEMO_BRANCH_SITE_CODE)->first();
            $this->assertNotNull($branch);
            $this->siteIds[] = (int) $branch->getKey();
            $this->assertSame((int) $branch->getKey(), (int) $transfer->to_site_id);

            $transferEpcId = (int) Epc::query()
                ->where('epc_uri', SeedOperationalChoreography::DEMO_TRANSFER_SSCC_URI)
                ->value('id');
            $this->assertGreaterThan(0, $transferEpcId);

            $shipEpcId = (int) Epc::query()
                ->where('epc_uri', SeedOperationalChoreography::DEMO_SSCC_URI)
                ->value('id');
            $this->assertGreaterThan(0, $shipEpcId);
            $this->assertTrue(
                OutboundShippingSession::query()
                    ->where('asn_number', SeedOperationalChoreography::DEMO_SHIP_ASN)
                    ->where('status', 'completed')
                    ->exists(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function incomplete_transfer_leaves_session_in_transit_for_a_live_destination_receive(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);

        try {
            Http::fake([
                'https://example.com/epcis-inbound' => Http::response('OK', 202),
                'https://l3.example.test/*' => Http::response('OK', 202),
            ]);

            $this->purgeStaleDemoChoreography();

            app(SeedMasterData::class)->handle();

            $result = app(SeedOperationalChoreography::class)->handle(
                completeShip: false,
                includeTransfer: true,
                completeTransfer: false,
            );

            $this->assertTrue($result['ship_deferred']);
            $this->assertTrue($result['transfer_deferred']);
            $this->assertNotNull($result['transfer_session_id']);

            $transfer = TransferringSession::query()->findOrFail($result['transfer_session_id']);
            $this->trackTransferArtifacts($transfer);

            $this->assertSame('in_transit', $transfer->status);
            $this->assertNotNull($transfer->transfer_events_generated_at);
            $this->assertNull($transfer->receive_events_generated_at);
            $this->assertNull($transfer->completed_at);
        } finally {
            $this->cleanup();
        }
    }

    private function purgeStaleDemoChoreography(): void
    {
        // Rows encrypted under another APP_KEY break Eloquent loads on the shared demo2 DB.
        OutboundConnection::query()
            ->where('name', 'Demo Downstream Pharmacy HTTPS')
            ->delete();

        $inboundDocumentIds = EpcisDocument::query()
            ->where('original_filename', SeedOperationalChoreography::DEMO_RECEIVE_FILENAME)
            ->pluck('id')
            ->all();

        if ($inboundDocumentIds !== []) {
            ReceivingSession::query()
                ->whereIn('epcis_document_id', $inboundDocumentIds)
                ->delete();

            EpcisDocument::query()->whereKey($inboundDocumentIds)->delete();
        }

        OutboundShippingSession::query()
            ->where('asn_number', SeedOperationalChoreography::DEMO_SHIP_ASN)
            ->delete();

        TransferringSession::query()
            ->where('notes', SeedOperationalChoreography::DEMO_TRANSFER_SESSION_NOTES)
            ->delete();

        $transferInboundDocumentIds = EpcisDocument::query()
            ->where('original_filename', SeedOperationalChoreography::DEMO_TRANSFER_RECEIVE_FILENAME)
            ->pluck('id')
            ->all();

        if ($transferInboundDocumentIds !== []) {
            ReceivingSession::query()
                ->whereIn('epcis_document_id', $transferInboundDocumentIds)
                ->delete();

            EpcisDocument::query()->whereKey($transferInboundDocumentIds)->delete();
        }

        Site::query()
            ->where('code', SeedOperationalChoreography::DEMO_BRANCH_SITE_CODE)
            ->delete();

        $this->purgeHierarchyDemoArtifacts();
    }

    private function purgeHierarchyDemoArtifacts(): void
    {
        EpcisDocument::query()
            ->where('notes', SeedOperationalChoreography::DEMO_RETURN_DOCUMENT_NOTES)
            ->delete();

        SsccLabelBatch::query()
            ->where('notes', SeedOperationalChoreography::DEMO_PACK_BATCH_NOTES)
            ->delete();

        $hierarchyInboundDocumentIds = EpcisDocument::query()
            ->where('original_filename', SeedOperationalChoreography::DEMO_HIERARCHY_RECEIVE_FILENAME)
            ->pluck('id')
            ->all();

        if ($hierarchyInboundDocumentIds !== []) {
            ReceivingSession::query()
                ->whereIn('epcis_document_id', $hierarchyInboundDocumentIds)
                ->delete();

            EpcisDocument::query()->whereKey($hierarchyInboundDocumentIds)->delete();
        }
    }

    private function trackReceiveArtifacts(ReceivingSession $session): void
    {
        $this->receivingSessionIds[] = (int) $session->getKey();

        if ($session->epcis_document_id !== null) {
            $this->documentIds[] = (int) $session->epcis_document_id;
        }

        if ($session->receiving_epcis_document_id !== null) {
            $this->documentIds[] = (int) $session->receiving_epcis_document_id;
        }

        $inbound = EpcisDocument::query()
            ->where('original_filename', SeedOperationalChoreography::DEMO_RECEIVE_FILENAME)
            ->pluck('id')
            ->all();

        foreach ($inbound as $id) {
            $this->documentIds[] = (int) $id;
        }
    }

    private function trackShipArtifacts(OutboundShippingSession $session): void
    {
        $this->shippingSessionIds[] = (int) $session->getKey();

        if ($session->epcis_document_id !== null) {
            $this->documentIds[] = (int) $session->epcis_document_id;
        }
    }

    private function trackTransferArtifacts(TransferringSession $session): void
    {
        $this->transferringSessionIds[] = (int) $session->getKey();

        if ($session->transfer_epcis_document_id !== null) {
            $this->documentIds[] = (int) $session->transfer_epcis_document_id;
        }

        $transferInboundDocumentIds = EpcisDocument::query()
            ->where('original_filename', SeedOperationalChoreography::DEMO_TRANSFER_RECEIVE_FILENAME)
            ->pluck('id')
            ->all();

        foreach ($transferInboundDocumentIds as $id) {
            $this->documentIds[] = (int) $id;
        }

        if ($transferInboundDocumentIds !== []) {
            $transferReceiveSessions = ReceivingSession::query()
                ->whereIn('epcis_document_id', $transferInboundDocumentIds)
                ->get();

            foreach ($transferReceiveSessions as $transferReceive) {
                $this->trackReceiveArtifacts($transferReceive);
            }
        }
    }

    private function initializeDemo2Tenant(TenantProfile $profile): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Distributor',
                'profile' => $profile,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
                'receiving_state' => 'IL',
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
            $tenant->forceFill([
                'profile' => $profile,
                'receiving_state' => $tenant->receiving_state ?: 'IL',
            ])->save();
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant->fresh());

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->shippingSessionIds !== []) {
            OutboundShippingSession::query()->whereKey($this->shippingSessionIds)->delete();
            $this->shippingSessionIds = [];
        }

        if ($this->transferringSessionIds !== []) {
            TransferringSession::query()->whereKey($this->transferringSessionIds)->delete();
            $this->transferringSessionIds = [];
        }

        if ($this->receivingSessionIds !== []) {
            ReceivingSession::query()->whereKey($this->receivingSessionIds)->delete();
            $this->receivingSessionIds = [];
        }

        $this->documentIds = array_values(array_unique($this->documentIds));

        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereKey($this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->packBatchIds !== []) {
            SsccLabelBatch::query()->whereKey($this->packBatchIds)->delete();
            $this->packBatchIds = [];
        }

        if ($this->siteIds !== []) {
            Site::query()->whereKey($this->siteIds)->delete();
            $this->siteIds = [];
        }

        tenancy()->end();
    }
}
