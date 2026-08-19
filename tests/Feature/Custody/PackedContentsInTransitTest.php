<?php

namespace Tests\Feature\Custody;

use App\Enums\EpcisAuthoredKind;
use App\Enums\TenantProfile;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Site;
use App\Models\Tenant;
use App\Services\Custody\EpcCustodyGate;
use App\Support\Gs1\Gtin;
use App\Support\Shipping\ShippableEpcsAtSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Custody and site inventory for units packed inside a container that ships.
 *
 * A shipping event names the pallet, never the cases and items inside it, so the
 * contents keep the aggregation event read at our own dock as their latest word on
 * where they are. Events are authored directly here — a pallet, a case inside it,
 * an item inside the case — so the assertions are about the custody predicate
 * climbing the hierarchy rather than about ship order plumbing.
 */
class PackedContentsInTransitTest extends TestCase
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
    private array $epcIds = [];

    #[Test]
    public function shipping_a_pallet_takes_the_case_and_item_inside_it_out_of_custody(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = $this->createSite();
            $siteId = (int) $site->getKey();

            $pallet = $this->createSscc();
            $case = $this->createSscc();
            $item = $this->createSgtin();

            $this->packInto($site, $pallet, $case);
            $this->packInto($site, $case, $item);

            $gate = app(EpcCustodyGate::class);
            $shippable = app(ShippableEpcsAtSite::class);
            $packedIds = [(int) $case->getKey(), (int) $item->getKey()];

            foreach ([$pallet, $case, $item] as $epc) {
                $this->assertTrue($gate->isInCustody($epc), 'Aggregated stock is on hand at the dock.');
                $this->assertTrue($shippable->contains($siteId, (int) $epc->getKey()));
            }

            // Ship the pallet alone, exactly as GenerateShippingEpcisEvents authors it.
            $this->authorShipping($site, $pallet);

            $this->assertFalse($gate->isInCustody($pallet->fresh()));

            // One level down and two levels down: both left inside the pallet.
            foreach ($packedIds as $epcId) {
                $this->assertFalse($gate->isInCustody(Epc::query()->findOrFail($epcId)));
                $this->assertFalse($shippable->contains($siteId, $epcId));
            }

            $this->assertSame([], $gate->epcIdsInCustody($packedIds));
            $this->assertSame([], array_intersect($packedIds, $shippable->epcIds($siteId)));

            $this->assertNotEmpty($pallet->sscc18);

            try {
                $gate->assertOperableFor([$item->fresh()], 'packing');
                $this->fail('Expected an item inside a shipped pallet to fail the custody assertion.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('packed inside', $e->getMessage());
                $this->assertStringContainsString((string) $pallet->sscc18, $e->getMessage());
            }

            // Receiving the pallet back ends the transit for everything inside it.
            $this->authorEvent(
                site: $site,
                bizStep: 'urn:epcglobal:cbv:bizstep:receiving',
                disposition: 'urn:epcglobal:cbv:disp:in_progress',
                authoredKind: EpcisAuthoredKind::Receiving,
                notes: 'Generated receiving EPCIS for a packed contents custody test.',
                epcRoles: [(int) $pallet->getKey() => 'epcList'],
            );

            foreach ($packedIds as $epcId) {
                $this->assertTrue($gate->isInCustody(Epc::query()->findOrFail($epcId)));
                $this->assertTrue($shippable->contains($siteId, $epcId));
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function an_unpacked_item_answers_for_its_own_location(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = $this->createSite();
            $siteId = (int) $site->getKey();

            $pallet = $this->createSscc();
            $item = $this->createSgtin();
            $link = $this->packInto($site, $pallet, $item);

            $this->authorShipping($site, $pallet);

            $gate = app(EpcCustodyGate::class);
            $shippable = app(ShippableEpcsAtSite::class);

            $this->assertFalse($gate->isInCustody($item->fresh()));

            // Closed link: the item was taken out of the pallet and stayed behind.
            $link->forceFill(['valid_to' => now()])->save();

            $this->assertTrue($gate->isInCustody($item->fresh()));
            $this->assertTrue($shippable->contains($siteId, (int) $item->getKey()));
        } finally {
            $this->cleanup();
        }
    }

    /**
     * The AggregationEvent (ADD) and open link a packing operation leaves behind.
     */
    private function packInto(Site $site, Epc $parent, Epc $child): AggregationLink
    {
        $event = $this->authorEvent(
            site: $site,
            bizStep: 'urn:epcglobal:cbv:bizstep:packing',
            disposition: 'urn:epcglobal:cbv:disp:in_progress',
            authoredKind: EpcisAuthoredKind::SsccAggregation,
            notes: 'Generated SSCC aggregation EPCIS for a packed contents custody test.',
            epcRoles: [
                (int) $parent->getKey() => 'parentID',
                (int) $child->getKey() => 'childEPC',
            ],
            eventType: 'AggregationEvent',
            action: 'ADD',
        );

        return AggregationLink::query()->create([
            'parent_epc_id' => $parent->getKey(),
            'child_epc_id' => $child->getKey(),
            'established_by_event_id' => $event->getKey(),
            'link_type' => 'aggregation',
            'valid_from' => now()->subMinute(),
            'valid_to' => null,
        ]);
    }

    /**
     * The shipping ObjectEvent an outbound ship order authors: in_transit, read at
     * the dock it left, and naming the outermost unit only.
     */
    private function authorShipping(Site $site, Epc $epc): void
    {
        $this->authorEvent(
            site: $site,
            bizStep: 'urn:epcglobal:cbv:bizstep:shipping',
            disposition: 'urn:epcglobal:cbv:disp:in_transit',
            authoredKind: EpcisAuthoredKind::Shipping,
            notes: 'Generated outbound shipping EPCIS for a packed contents custody test.',
            epcRoles: [(int) $epc->getKey() => 'epcList'],
        );
    }

    /**
     * @param  array<int, string>  $epcRoles  EPC id => role on the event
     */
    private function authorEvent(
        Site $site,
        string $bizStep,
        string $disposition,
        EpcisAuthoredKind $authoredKind,
        string $notes,
        array $epcRoles,
        string $eventType = 'ObjectEvent',
        string $action = 'OBSERVE',
    ): EpcisEvent {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'authored_kind' => $authoredKind,
            'status' => 'parsed',
            'original_filename' => 'packed-contents-'.Str::random(6).'.xml',
            'notes' => $notes,
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => $eventType,
            'event_time' => now(),
            'record_time' => now(),
            'event_timezone_offset' => '+00:00',
            'action' => $action,
            'biz_step' => $bizStep,
            'disposition' => $disposition,
            'read_point_gln' => (string) $site->gln,
            'biz_location_gln' => (string) $site->gln,
        ]);
        $this->eventIds[] = (int) $event->getKey();

        $rows = [];
        foreach ($epcRoles as $epcId => $role) {
            $rows[] = [
                'event_id' => $event->getKey(),
                'epc_id' => $epcId,
                'role' => $role,
            ];
        }

        DB::table('event_epcs')->insertOrIgnore($rows);

        return $event;
    }

    private function createSscc(): Epc
    {
        return $this->createEpc('urn:epc:id:sscc:030116.0'.random_int(1000000000, 9999999999));
    }

    private function createSgtin(): Epc
    {
        return $this->createEpc(
            'urn:epc:id:sgtin:030116.3'.substr((string) random_int(10000000, 99999999), 0, 6)
            .'.PC'.random_int(10000000, 99999999),
        );
    }

    private function createEpc(string $uri): Epc
    {
        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->epcIds[] = (int) $epc->getKey();

        return $epc;
    }

    private function createSite(): Site
    {
        $site = Site::query()->create([
            'name' => 'Packed Contents '.Str::random(6),
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
            // Links and event_epcs cascade from the establishing event.
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
