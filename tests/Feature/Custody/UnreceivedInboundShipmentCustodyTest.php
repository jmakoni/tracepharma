<?php

namespace Tests\Feature\Custody;

use App\Enums\EpcisAuthoredKind;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Site;
use App\Models\Tenant;
use App\Services\Custody\EpcCustodyGate;
use App\Support\Gs1\Gtin;
use App\Support\Shipping\ShippableEpcsAtSite;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Custody for stock a supplier has announced but nobody has received.
 *
 * Suppliers commonly put the ship-to dock in bizLocation, so an inbound shipping
 * event carries one of our GLNs while the pallet is still on a truck. Stock we did
 * not commission enters our custody at the receiving scan, so the ASN alone must
 * not make the unit packable, shippable or transferable — and the receiving event
 * the floor authors afterwards must.
 */
class UnreceivedInboundShipmentCustodyTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SUPPLIER_GLN = '0301160000009';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    #[Test]
    public function an_inbound_shipment_naming_our_dock_grants_no_custody_until_it_is_received(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = $this->createSite();
            $siteId = (int) $site->getKey();
            $epc = $this->createSgtin();
            $epcId = (int) $epc->getKey();

            $this->authorSupplierShipment($site, $epc);

            $gate = app(EpcCustodyGate::class);
            $shippable = app(ShippableEpcsAtSite::class);

            $this->assertFalse(
                $gate->isInCustody($epc->fresh()),
                'A supplier naming our dock as ship-to has not handed us the goods.',
            );
            $this->assertSame([], $gate->epcIdsInCustody([$epcId]));
            $this->assertFalse($shippable->contains($siteId, $epcId));
            $this->assertNotContains($epcId, $shippable->epcIds($siteId));

            try {
                $gate->assertOperableFor([$epc->fresh()], 'packing');
                $this->fail('Expected an unreceived inbound shipment to fail the custody assertion.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('has not been received', $e->getMessage());
                $this->assertStringContainsString((string) $site->gln, $e->getMessage());
            }

            // The receiving event GenerateReceivingEpcisEvents authors on receipt is
            // what takes custody, and it becomes the EPC's latest trackable event.
            $this->authorTenantReceipt($site, $epc);

            $gate = app(EpcCustodyGate::class);
            $shippable = app(ShippableEpcsAtSite::class);

            $this->assertTrue($gate->isInCustody($epc->fresh()));
            $this->assertSame([$epcId], $gate->epcIdsInCustody([$epcId]));
            $this->assertTrue($shippable->contains($siteId, $epcId));
            $this->assertContains($epcId, $shippable->epcIds($siteId));

            $gate->assertOperableFor([$epc->fresh()], 'packing');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function an_inbound_shipment_read_at_our_dock_grants_no_custody_either(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = $this->createSite();
            $epc = $this->createSgtin();

            // Same claim with our GLN on readPoint instead: a shipment read at our
            // dock is a delivery in progress, not a receipt.
            $this->authorEvent(
                epc: $epc,
                bizStep: 'urn:epcglobal:cbv:bizstep:shipping',
                disposition: 'urn:epcglobal:cbv:disp:in_transit',
                direction: 'inbound',
                authoredKind: null,
                readPointGln: (string) $site->gln,
                bizLocationGln: null,
            );

            $this->assertFalse(app(EpcCustodyGate::class)->isInCustody($epc->fresh()));
            $this->assertFalse(app(ShippableEpcsAtSite::class)->contains(
                (int) $site->getKey(),
                (int) $epc->getKey(),
            ));
        } finally {
            $this->cleanup();
        }
    }

    /**
     * The shipping ObjectEvent on a supplier's DSCSA shipment: read at their dock,
     * with ours as the bizLocation the goods are headed for.
     */
    private function authorSupplierShipment(Site $site, Epc $epc): void
    {
        $this->authorEvent(
            epc: $epc,
            bizStep: 'urn:epcglobal:cbv:bizstep:shipping',
            disposition: 'urn:epcglobal:cbv:disp:in_transit',
            direction: 'inbound',
            authoredKind: null,
            readPointGln: self::SUPPLIER_GLN,
            bizLocationGln: (string) $site->gln,
            eventTime: now()->subHour(),
        );
    }

    private function authorTenantReceipt(Site $site, Epc $epc): void
    {
        $this->authorEvent(
            epc: $epc,
            bizStep: 'urn:epcglobal:cbv:bizstep:receiving',
            disposition: 'urn:epcglobal:cbv:disp:in_progress',
            direction: 'outbound',
            authoredKind: EpcisAuthoredKind::Receiving,
            readPointGln: (string) $site->gln,
            bizLocationGln: (string) $site->gln,
        );
    }

    private function authorEvent(
        Epc $epc,
        string $bizStep,
        string $disposition,
        string $direction,
        ?EpcisAuthoredKind $authoredKind,
        ?string $readPointGln,
        ?string $bizLocationGln,
        ?Carbon $eventTime = null,
    ): EpcisEvent {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => $direction,
            'authored_kind' => $authoredKind,
            'status' => 'parsed',
            'original_filename' => 'unreceived-inbound-'.Str::random(6).'.xml',
            'notes' => $authoredKind === null
                ? null
                : 'Generated '.$authoredKind->value.' EPCIS for an unreceived inbound shipment custody test.',
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $eventTime ??= now();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => $eventTime,
            'record_time' => now(),
            'event_timezone_offset' => '+00:00',
            'action' => 'OBSERVE',
            'biz_step' => $bizStep,
            'disposition' => $disposition,
            'read_point_gln' => $readPointGln,
            'biz_location_gln' => $bizLocationGln,
        ]);
        $this->eventIds[] = (int) $event->getKey();

        DB::table('event_epcs')->insertOrIgnore([[
            'event_id' => $event->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]]);

        return $event;
    }

    private function createSgtin(): Epc
    {
        $uri = 'urn:epc:id:sgtin:030116.3'.substr((string) random_int(10000000, 99999999), 0, 6)
            .'.UI'.random_int(10000000, 99999999);

        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->epcIds[] = (int) $epc->getKey();

        return $epc;
    }

    private function createSite(): Site
    {
        $site = Site::query()->create([
            'name' => 'Unreceived Inbound '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        return $site;
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

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
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

        tenancy()->end();
    }
}
