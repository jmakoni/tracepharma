<?php

namespace Tests\Feature\Shipping;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Actions\Shipping\ProcessWmsShipConfirm;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Exceptions\WmsIdempotencyConflictException;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Sgln;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessWmsShipConfirmTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private const SECOND_SSCC_URI = 'urn:epc:id:sscc:030116.01001227061';

    private static bool $demo2TenantReady = false;

    private ?TenantProfile $priorProfile = null;

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $receivingSessionIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    #[Test]
    public function complete_false_stops_before_send_and_returns_scanned_without_blockers(): void
    {
        $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);

            $site = $this->createShipSite();
            $this->makeEpcShippableAtSite($site);

            $result = app(ProcessWmsShipConfirm::class)->handle([
                'scans' => [self::SSCC_URI],
                'complete' => false,
            ]);

            $this->assertSame(422, $result['http_status']);
            $this->assertSame('scanned', $result['status']);
            $this->assertSame(1, $result['confirmed_count']);
            $this->assertNotEmpty($result['blockers']);

            $session = OutboundShippingSession::query()->find($result['session_id']);
            $this->assertNotNull($session);
            $this->sessionIds[] = (int) $session->getKey();
            $this->assertSame('in_progress', $session->status);
            $this->assertNull($session->completed_at);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function idempotency_key_replays_existing_session_without_duplicate_open(): void
    {
        $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);

            $site = $this->createShipSite();
            $this->makeEpcShippableAtSite($site);

            $key = 'wms-idem-'.uniqid('', true);

            $beforeCount = OutboundShippingSession::query()->count();

            $first = app(ProcessWmsShipConfirm::class)->handle([
                'scans' => [self::SSCC_URI],
                'complete' => false,
            ], $key);

            $this->sessionIds[] = (int) $first['session_id'];

            $second = app(ProcessWmsShipConfirm::class)->handle([
                'scans' => [self::SSCC_URI],
                'complete' => false,
            ], $key);

            $this->assertTrue($second['idempotent_replay'] ?? false);
            $this->assertSame($first['session_id'], $second['session_id']);
            $this->assertSame($beforeCount + 1, OutboundShippingSession::query()->count());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function idempotency_keys_order_1_and_order_10_do_not_collide(): void
    {
        $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);

            $site = $this->createShipSite();
            $this->makeEpcShippableAtSite($site, self::SSCC_URI);
            $this->makeEpcShippableAtSite($site, self::SECOND_SSCC_URI);

            $orderOne = app(ProcessWmsShipConfirm::class)->handle([
                'scans' => [self::SSCC_URI],
                'complete' => false,
            ], 'order-1');
            $this->sessionIds[] = (int) $orderOne['session_id'];

            $orderTen = app(ProcessWmsShipConfirm::class)->handle([
                'scans' => [self::SECOND_SSCC_URI],
                'complete' => false,
            ], 'order-10');
            $this->sessionIds[] = (int) $orderTen['session_id'];

            $this->assertNotSame($orderOne['session_id'], $orderTen['session_id']);

            $replayOne = app(ProcessWmsShipConfirm::class)->handle([
                'scans' => [self::SSCC_URI],
                'complete' => false,
            ], 'order-1');

            $this->assertTrue($replayOne['idempotent_replay'] ?? false);
            $this->assertSame($orderOne['session_id'], $replayOne['session_id']);
            $this->assertNotSame($orderTen['session_id'], $replayOne['session_id']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function idempotency_replay_rejects_party_change_with_conflict(): void
    {
        $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);

            $site = $this->createShipSite();
            $this->makeEpcShippableAtSite($site);

            $customerA = $this->createCustomerPartner('WMS Customer A');
            $customerB = $this->createCustomerPartner('WMS Customer B');
            $key = 'wms-party-idem-'.uniqid('', true);

            $first = app(ProcessWmsShipConfirm::class)->handle([
                'scans' => [self::SSCC_URI],
                'complete' => false,
                'trading_partner_id' => (int) $customerA->getKey(),
            ], $key);
            $this->sessionIds[] = (int) $first['session_id'];

            $this->expectException(WmsIdempotencyConflictException::class);

            app(ProcessWmsShipConfirm::class)->handle([
                'scans' => [self::SSCC_URI],
                'complete' => false,
                'trading_partner_id' => (int) $customerB->getKey(),
            ], $key);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function idempotency_replay_rejects_reference_change_with_conflict(): void
    {
        $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);

            $site = $this->createShipSite();
            $this->makeEpcShippableAtSite($site);

            $key = 'wms-ref-idem-'.uniqid('', true);

            $first = app(ProcessWmsShipConfirm::class)->handle([
                'scans' => [self::SSCC_URI],
                'complete' => false,
                'asn_number' => 'ASN-001',
                'customer_po' => 'PO-100',
                'invoice_number' => 'INV-900',
            ], $key);
            $this->sessionIds[] = (int) $first['session_id'];

            $this->expectException(WmsIdempotencyConflictException::class);

            app(ProcessWmsShipConfirm::class)->handle([
                'scans' => [self::SSCC_URI],
                'complete' => false,
                'asn_number' => 'ASN-002',
                'customer_po' => 'PO-100',
                'invoice_number' => 'INV-900',
            ], $key);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function idempotency_replay_rejects_expected_count_change_with_conflict(): void
    {
        $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);

            $site = $this->createShipSite();
            $this->makeEpcShippableAtSite($site);

            $key = 'wms-expected-idem-'.uniqid('', true);

            $first = app(ProcessWmsShipConfirm::class)->handle([
                'scans' => [self::SSCC_URI],
                'complete' => false,
                'expected_count' => 2,
            ], $key);
            $this->sessionIds[] = (int) $first['session_id'];

            $session = OutboundShippingSession::query()->findOrFail($first['session_id']);
            $this->assertSame(2, (int) $session->expected_count);

            $this->expectException(WmsIdempotencyConflictException::class);

            app(ProcessWmsShipConfirm::class)->handle([
                'scans' => [self::SSCC_URI],
                'complete' => false,
                'expected_count' => 99,
            ], $key);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function idempotency_replay_omitting_complete_does_not_complete_partial_session(): void
    {
        $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);

            $site = $this->createShipSite();
            $this->makeEpcShippableAtSite($site);
            $customer = $this->createCustomerPartner('WMS Complete Replay');
            $shipTo = Site::query()->create([
                'trading_partner_id' => $customer->getKey(),
                'name' => 'WMS Customer HQ',
                'gln' => $customer->gln,
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => false,
            ]);
            $this->siteIds[] = (int) $shipTo->getKey();

            $key = 'wms-complete-replay-'.uniqid('', true);
            $payload = [
                'scans' => [self::SSCC_URI],
                'complete' => false,
                'trading_partner_id' => (int) $customer->getKey(),
                'ship_to_site_id' => (int) $shipTo->getKey(),
                'asn_number' => 'ASN-WMS-001',
                'customer_po' => 'PO-WMS-001',
                'dscsa_affirm' => true,
            ];

            $first = app(ProcessWmsShipConfirm::class)->handle($payload, $key);
            $this->sessionIds[] = (int) $first['session_id'];

            $this->assertSame(200, $first['http_status']);
            $this->assertSame('scanned', $first['status']);

            $session = OutboundShippingSession::query()->findOrFail($first['session_id']);
            $this->assertSame('in_progress', $session->status);
            $this->assertFalse((bool) $session->wms_complete);

            $replayPayload = $payload;
            unset($replayPayload['complete']);

            try {
                app(ProcessWmsShipConfirm::class)->handle($replayPayload, $key);
                $this->fail('Expected WmsIdempotencyConflictException when replay omits complete.');
            } catch (WmsIdempotencyConflictException $e) {
                $this->assertStringContainsString('complete', $e->getMessage());
            }

            $session->refresh();
            $this->assertSame('in_progress', $session->status);
            $this->assertNull($session->completed_at);
            $this->assertNull($session->shipping_events_generated_at);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function idempotency_replay_retries_missing_scans_after_partial_scan_errors(): void
    {
        $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);

            $site = $this->createShipSite();
            $this->makeEpcShippableAtSite($site, self::SSCC_URI);

            $key = 'wms-partial-idem-'.uniqid('', true);
            $invalidScan = 'urn:epc:id:sscc:030116.99999999999';

            $partial = app(ProcessWmsShipConfirm::class)->handle([
                'scans' => [self::SSCC_URI, $invalidScan],
                'complete' => false,
            ], $key);
            $this->sessionIds[] = (int) $partial['session_id'];

            $this->assertSame(422, $partial['http_status']);
            $this->assertSame('scan_errors', $partial['status']);
            $this->assertSame(1, $partial['confirmed_count']);
            $this->assertSame(
                $key,
                OutboundShippingSession::query()->findOrFail($partial['session_id'])->wms_idempotency_key,
            );

            $replay = app(ProcessWmsShipConfirm::class)->handle([
                'scans' => [self::SSCC_URI],
                'complete' => false,
            ], $key);
            $this->sessionIds[] = (int) $replay['session_id'];

            $this->assertTrue($replay['idempotent_replay'] ?? false);
            $this->assertSame($partial['session_id'], $replay['session_id']);
            $this->assertSame('scanned', $replay['status']);
            $this->assertSame(1, $replay['confirmed_count']);

            $superset = app(ProcessWmsShipConfirm::class)->handle([
                'scans' => [self::SSCC_URI, $invalidScan],
                'complete' => false,
            ], $key);
            $this->sessionIds[] = (int) $superset['session_id'];

            $this->assertTrue($superset['idempotent_replay'] ?? false);
            $this->assertSame($partial['session_id'], $superset['session_id']);
            $this->assertSame('scan_errors', $superset['status']);
            $this->assertSame(1, $superset['confirmed_count']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function idempotency_replay_rejects_payload_that_omits_confirmed_scans(): void
    {
        $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);

            $site = $this->createShipSite();
            $this->makeEpcShippableAtSite($site, self::SSCC_URI);
            $this->makeEpcShippableAtSite($site, self::SECOND_SSCC_URI);

            $key = 'wms-subset-idem-'.uniqid('', true);

            $first = app(ProcessWmsShipConfirm::class)->handle([
                'scans' => [self::SSCC_URI],
                'complete' => false,
            ], $key);
            $this->sessionIds[] = (int) $first['session_id'];
            $this->assertSame('scanned', $first['status']);
            $this->assertSame(1, $first['confirmed_count']);

            $this->expectException(WmsIdempotencyConflictException::class);

            app(ProcessWmsShipConfirm::class)->handle([
                'scans' => [self::SECOND_SSCC_URI],
                'complete' => false,
            ], $key);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function rejects_empty_scans(): void
    {
        $this->initializeWholesalerTenant();

        try {
            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('At least one scan is required.');

            app(ProcessWmsShipConfirm::class)->handle(['scans' => []]);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function confirms_scans_and_returns_scanned_when_customer_missing(): void
    {
        $this->initializeWholesalerTenant();

        try {
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);

            $site = $this->createShipSite();
            $this->makeEpcShippableAtSite($site);

            $result = app(ProcessWmsShipConfirm::class)->handle([
                'scans' => [self::SSCC_URI],
            ]);

            $this->assertSame(422, $result['http_status']);
            $this->assertSame('scanned', $result['status']);
            $this->assertSame(1, $result['confirmed_count']);
            $this->assertContains('Select a customer (trading partner).', $result['blockers']);

            $session = OutboundShippingSession::query()->find($result['session_id']);
            $this->assertNotNull($session);
            $this->sessionIds[] = (int) $session->getKey();
            $this->assertSame('in_progress', $session->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function wms_ship_confirm_succeeds_without_authenticated_user_when_job_roles_are_enabled(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
            $tenant->save();

            auth()->logout();

            $site = $this->createShipSite();

            $session = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->sessionIds[] = (int) $session->getKey();
            $this->assertSame('open', $session->status);
        } finally {
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->save();
            $this->cleanup();
        }
    }

    private function createShipSite(): Site
    {
        $siteGln = '0366159000'.random_int(100, 999);

        $site = Site::query()->create([
            'name' => 'WMS Bridge Ship Site',
            'gln' => $siteGln,
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        TenantSettings::forTenant(tenant())->saveOrganization([
            'gln' => $siteGln,
            'company_prefix' => '036615',
            'default_ship_from_site_id' => (int) $site->getKey(),
            'default_receive_site_id' => (int) $site->getKey(),
        ]);

        return $site;
    }

    private function makeEpcShippableAtSite(Site $site, string $ssccUri = self::SSCC_URI): void
    {
        $document = $this->ingestMinimalFixture($ssccUri);
        $this->documentIds[] = (int) $document->getKey();

        $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
        $this->receivingSessionIds[] = (int) $session->getKey();
        $session->forceFill(['site_id' => (int) $site->getKey()])->save();

        app(ConfirmReceivingScan::class)->handle(
            $session->fresh(),
            $ssccUri,
            userId: null,
            autoConfirmChildren: true,
        );

        $session = $session->fresh();
        $session->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
        ])->save();

        if ($session->receiving_epcis_document_id !== null) {
            $this->documentIds[] = (int) $session->receiving_epcis_document_id;
        }
    }

    private function ingestMinimalFixture(string $ssccUri = self::SSCC_URI): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) Str::uuid(), $xml);
        $xml = str_replace(self::SSCC_URI, $ssccUri, $xml);
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

    private function createCustomerPartner(string $name): TradingPartner
    {
        $gln = '037100'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);

        $partner = TradingPartner::query()->create([
            'name' => $name,
            'gln' => $gln,
            'sgln' => Sgln::toUrn($gln, 6),
            'partner_type' => PartnerType::Pharmacy,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->partnerIds[] = (int) $partner->getKey();

        return $partner;
    }

    private function initializeWholesalerTenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Wholesaler',
                'profile' => TenantProfile::DrugWholesaler,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        $this->priorProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);

        $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant->fresh());

        OutboundShippingSession::query()->whereIn('status', ['open', 'in_progress'])->delete();

        return $tenant;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->sessionIds !== []) {
                OutboundShippingSession::query()->whereKey($this->sessionIds)->delete();
                $this->sessionIds = [];
            }

            if ($this->receivingSessionIds !== []) {
                ReceivingSession::query()->whereKey($this->receivingSessionIds)->delete();
                $this->receivingSessionIds = [];
            }

            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereKey($this->documentIds)->delete();
                $this->documentIds = [];
            }

            if ($this->siteIds !== []) {
                OutboundShippingSession::query()->whereIn('site_id', $this->siteIds)->delete();
                Site::query()->whereKey($this->siteIds)->delete();
                $this->siteIds = [];
            }

            if ($this->partnerIds !== []) {
                TradingPartner::query()->whereKey($this->partnerIds)->delete();
                $this->partnerIds = [];
            }
        }

        if ($this->priorProfile !== null) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
            if ($tenant !== null) {
                $tenant->forceFill(['profile' => $this->priorProfile])->save();
            }
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->priorProfile = null;
    }
}
