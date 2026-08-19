<?php

namespace Tests\Feature\Transferring;

use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Transferring\ConfirmTransferringScan;
use App\Actions\Transferring\OpenTransferringSession;
use App\Enums\EpcisAuthoredKind;
use App\Enums\ReceivingSessionKind;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Services\Custody\EpcCustodyGate;
use App\Support\Gs1\Gtin;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Custody and site inventory while an intracompany transfer is on the road.
 *
 * The transfer EPCIS is authored here rather than by shipping a session, so the
 * assertions are about the custody predicate and not about SGLN resolution.
 */
class TransferInTransitCustodyTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $receivingSessionIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    #[Test]
    public function transfer_in_transit_leaves_stock_shippable_at_neither_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $epc = $this->createEpc();

            $this->authorReceivingEvent($fromSite, $epc);

            $this->assertTrue(
                app(ShippableEpcsAtSite::class)->contains((int) $fromSite->getKey(), (int) $epc->getKey()),
                'Stock received at the origin should be on hand there before the transfer ships.',
            );

            $this->authorTransferInTransitEvent($fromSite, $epc);

            $shippable = app(ShippableEpcsAtSite::class);
            $this->assertFalse($shippable->contains((int) $fromSite->getKey(), (int) $epc->getKey()));
            $this->assertNotContains((int) $epc->getKey(), $shippable->epcIds((int) $fromSite->getKey()));
            $this->assertNotContains((int) $epc->getKey(), $shippable->epcIds((int) $toSite->getKey()));

            // Custody is what pack, unpack and break-pack gate on, so the unit must
            // read as out of our hands at every site while it is on the truck.
            $this->assertFalse(app(EpcCustodyGate::class)->isInCustody($epc->fresh()));
            $this->assertSame([], app(EpcCustodyGate::class)->epcIdsInCustody([$epc->fresh()]));

            try {
                app(EpcCustodyGate::class)->assertOperableFor($epc->fresh(), 'packing');
                $this->fail('Expected an in-transit transfer to block packing.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('in transit', $e->getMessage());
            }

            // Receiving at the destination ends the transit and restores custody there.
            $this->authorReceivingEvent($toSite, $epc);

            $shippable = app(ShippableEpcsAtSite::class);
            $this->assertTrue($shippable->contains((int) $toSite->getKey(), (int) $epc->getKey()));
            $this->assertFalse($shippable->contains((int) $fromSite->getKey(), (int) $epc->getKey()));
            $this->assertTrue(app(EpcCustodyGate::class)->isInCustody($epc->fresh()));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function transfer_scan_rejects_a_unit_resting_at_another_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $epc = $this->createEpc();

            // In organization custody, but on the wrong dock: transferring it out of
            // the from site would claim a movement that never happened there.
            $this->authorReceivingEvent($toSite, $epc);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();

            $result = app(ConfirmTransferringScan::class)->handle($session, (string) $epc->epc_uri);

            $this->assertFalse($result['ok']);
            $this->assertSame('not_at_from_site', $result['effect']);
            $this->assertSame('This unit is not on hand at the transfer-from site.', $result['message']);
            $this->assertNull($result['line']);
            $this->assertSame(0, (int) $session->fresh()->confirmed_count);
            $this->assertSame(0, TransferringScanLine::query()
                ->where('transferring_session_id', $session->getKey())
                ->count());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function transfer_scan_rejects_a_unit_already_in_transit_from_the_from_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $epc = $this->createEpc();

            $this->authorReceivingEvent($fromSite, $epc);
            $this->authorTransferInTransitEvent($fromSite, $epc);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();

            $result = app(ConfirmTransferringScan::class)->handle($session, (string) $epc->epc_uri);

            $this->assertFalse($result['ok']);
            $this->assertSame('not_at_from_site', $result['effect']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function closing_a_transfer_receive_short_reports_only_what_was_received(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $arrived = $this->createEpc();
            $missing = $this->createEpc();

            foreach ([$arrived, $missing] as $epc) {
                $this->authorReceivingEvent($fromSite, $epc);
                $this->authorTransferInTransitEvent($fromSite, $epc);
            }

            $this->authorReceivingEvent($toSite, $arrived);

            $transfer = TransferringSession::query()->create([
                'from_site_id' => $fromSite->getKey(),
                'to_site_id' => $toSite->getKey(),
                'status' => 'in_transit',
                'confirmed_count' => 2,
                'received_count' => 1,
                'shipped_at' => now(),
                'opened_at' => now(),
            ]);
            $this->sessionIds[] = (int) $transfer->getKey();

            $this->createTransferLine($transfer, $arrived, 'received');
            $this->createTransferLine($transfer, $missing, 'confirmed');

            // A receive closed out with a line never scanned: the operator ended the
            // session, one unit never made it off the truck.
            $receiving = ReceivingSession::query()->create([
                'session_kind' => ReceivingSessionKind::TransferReceive,
                'transferring_session_id' => $transfer->getKey(),
                'site_id' => $toSite->getKey(),
                'status' => 'completed',
                'expected_parent_count' => 2,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
                'completed_at' => now(),
            ]);
            $this->receivingSessionIds[] = (int) $receiving->getKey();

            $this->createReceivingLine($receiving, $arrived, 'confirmed');
            $this->createReceivingLine($receiving, $missing, 'expected');

            app(CompleteReceivingSession::class)->handle($receiving->fresh());

            $transfer->refresh();
            $this->assertSame('completed', $transfer->status);
            $this->assertSame(1, (int) $transfer->received_count, 'The shortfall must not be counted as received.');

            $missingLine = TransferringScanLine::query()
                ->where('transferring_session_id', $transfer->getKey())
                ->where('epc_id', $missing->getKey())
                ->firstOrFail();
            $this->assertSame('confirmed', $missingLine->status);
            $this->assertNull($missingLine->received_at);

            // The unit that never arrived must not reappear as stock at the origin.
            $shippable = app(ShippableEpcsAtSite::class);
            $this->assertFalse($shippable->contains((int) $fromSite->getKey(), (int) $missing->getKey()));
            $this->assertFalse($shippable->contains((int) $toSite->getKey(), (int) $missing->getKey()));
            $this->assertFalse(app(EpcCustodyGate::class)->isInCustody($missing->fresh()));

            $this->assertTrue($shippable->contains((int) $toSite->getKey(), (int) $arrived->getKey()));
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createTransferSites(Tenant $tenant): array
    {
        $fromSite = Site::query()->create([
            'name' => 'Transit From '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $fromSite->getKey();

        $toSite = Site::query()->create([
            'name' => 'Transit To '.Str::random(6),
            'gln' => $this->uniqueGln(),
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

    private function createEpc(): Epc
    {
        $uri = 'urn:epc:id:sgtin:030116.3'.substr((string) random_int(10000000, 99999999), 0, 6)
            .'.TT'.random_int(10000000, 99999999);

        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->epcIds[] = (int) $epc->getKey();

        return $epc;
    }

    private function createTransferLine(TransferringSession $transfer, Epc $epc, string $status): void
    {
        TransferringScanLine::query()->create([
            'transferring_session_id' => $transfer->getKey(),
            'epc_id' => $epc->getKey(),
            'status' => $status,
            'scan_raw' => (string) $epc->epc_uri,
            'confirmed_at' => now(),
            'received_at' => $status === 'received' ? now() : null,
        ]);
    }

    private function createReceivingLine(ReceivingSession $session, Epc $epc, string $status): void
    {
        ReceivingScanLine::query()->create([
            'receiving_session_id' => $session->getKey(),
            'epc_id' => $epc->getKey(),
            'parent_epc_id' => null,
            'line_role' => 'parent',
            'status' => $status,
        ]);
    }

    /**
     * The receiving ObjectEvent GenerateReceivingEpcisEvents authors on receipt.
     */
    private function authorReceivingEvent(Site $site, Epc $epc): void
    {
        $this->authorEvent(
            site: $site,
            epc: $epc,
            bizStep: 'urn:epcglobal:cbv:bizstep:receiving',
            disposition: 'urn:epcglobal:cbv:disp:in_progress',
            authoredKind: EpcisAuthoredKind::Receiving,
            notes: 'Generated receiving EPCIS for a transfer custody test.',
        );
    }

    /**
     * The shipping ObjectEvent GenerateTransferringEpcisEvents authors when a
     * transfer ships: in_transit, but still carrying the origin GLN.
     */
    private function authorTransferInTransitEvent(Site $site, Epc $epc): void
    {
        $this->authorEvent(
            site: $site,
            epc: $epc,
            bizStep: 'urn:epcglobal:cbv:bizstep:shipping',
            disposition: 'urn:epcglobal:cbv:disp:in_transit',
            authoredKind: EpcisAuthoredKind::Transferring,
            notes: 'Generated transferring EPCIS (intracompany custody) for a transfer custody test.',
        );
    }

    private function authorEvent(
        Site $site,
        Epc $epc,
        string $bizStep,
        string $disposition,
        EpcisAuthoredKind $authoredKind,
        string $notes,
    ): void {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'authored_kind' => $authoredKind,
            'status' => 'parsed',
            'original_filename' => 'transfer-custody-'.Str::random(6).'.xml',
            'notes' => $notes,
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => now(),
            'record_time' => now(),
            'event_timezone_offset' => '+00:00',
            'action' => 'OBSERVE',
            'biz_step' => $bizStep,
            'disposition' => $disposition,
            'read_point_gln' => (string) $site->gln,
            'biz_location_gln' => (string) $site->gln,
        ]);
        $this->eventIds[] = (int) $event->getKey();

        DB::table('event_epcs')->insertOrIgnore([[
            'event_id' => $event->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]]);
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
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
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->receivingSessionIds !== []) {
            ReceivingScanLine::query()->whereIn('receiving_session_id', $this->receivingSessionIds)->delete();
            ReceivingSession::query()->whereIn('id', $this->receivingSessionIds)->delete();
            $this->receivingSessionIds = [];
        }

        if ($this->sessionIds !== []) {
            TransferringScanLine::query()->whereIn('transferring_session_id', $this->sessionIds)->delete();
            TransferringSession::query()->whereIn('id', $this->sessionIds)->delete();
            $this->sessionIds = [];
        }

        if ($this->eventIds !== []) {
            DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
            EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
            $this->eventIds = [];
        }

        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->epcIds !== []) {
            Epc::query()->whereIn('id', $this->epcIds)->delete();
            $this->epcIds = [];
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

        tenancy()->end();
    }
}
