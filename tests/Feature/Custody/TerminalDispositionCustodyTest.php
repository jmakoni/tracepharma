<?php

namespace Tests\Feature\Custody;

use App\Actions\Transferring\CompleteTransferringSession;
use App\Actions\Transferring\ConfirmTransferringScan;
use App\Actions\Transferring\OpenTransferringSession;
use App\Enums\EpcisAuthoredKind;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Services\Custody\EpcCustodyGate;
use App\Support\Custody\TerminalEpcDisposition;
use App\Support\Gs1\Gtin;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Custody and site inventory for units a terminal disposition has retired.
 *
 * A destroy, recall or decommission event is read at our own dock, so the unit
 * keeps reporting our GLN as its last known location: only the disposition says
 * it is gone. Events are authored directly here so the assertions are about the
 * custody predicate rather than about how a decommission workflow writes them.
 */
class TerminalDispositionCustodyTest extends TestCase
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
    private array $epcIds = [];

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    #[Test]
    public function a_destroyed_unit_leaves_custody_and_the_pick_list(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$site] = $this->createTransferSites($tenant);
            $siteId = (int) $site->getKey();
            $epc = $this->createEpc();

            $this->authorReceivingEvent($site, $epc);

            $gate = app(EpcCustodyGate::class);
            $shippable = app(ShippableEpcsAtSite::class);

            $this->assertTrue($gate->isInCustody($epc->fresh()));
            $this->assertTrue($shippable->contains($siteId, (int) $epc->getKey()));

            $this->authorTerminalEvent($site, $epc, 'urn:epcglobal:cbv:disp:destroyed');

            $this->assertFalse(
                $gate->isInCustody($epc->fresh()),
                'A destroyed unit is standing at our dock and is still nothing we may operate on.',
            );
            $this->assertSame([], $gate->epcIdsInCustody([$epc->fresh()]));

            $this->assertFalse($shippable->contains($siteId, (int) $epc->getKey()));
            $this->assertNotContains((int) $epc->getKey(), $shippable->epcIds($siteId));
            $this->assertSame([], $shippable->filter($siteId, [(int) $epc->getKey()]));

            try {
                $gate->assertOperableFor($epc->fresh(), 'packing');
                $this->fail('Expected a destroyed unit to fail the custody assertion.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('destroyed', $e->getMessage());
                $this->assertStringContainsString('correct the event on record', $e->getMessage());
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function every_terminal_disposition_retires_the_unit(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$site] = $this->createTransferSites($tenant);
            $siteId = (int) $site->getKey();

            $gate = app(EpcCustodyGate::class);
            $shippable = app(ShippableEpcsAtSite::class);

            foreach (TerminalEpcDisposition::DISPOSITIONS as $local) {
                $epc = $this->createEpc();
                $this->authorReceivingEvent($site, $epc);

                $this->assertTrue(
                    $shippable->contains($siteId, (int) $epc->getKey()),
                    $local.': on hand before the terminal event',
                );

                $this->authorTerminalEvent($site, $epc, 'urn:epcglobal:cbv:disp:'.$local);

                $this->assertFalse($gate->isInCustody($epc->fresh()), $local.': out of custody');
                $this->assertFalse($shippable->contains($siteId, (int) $epc->getKey()), $local.': not shippable');
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function a_recall_reported_by_a_partner_retires_the_unit_too(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$site] = $this->createTransferSites($tenant);
            $epc = $this->createEpc();

            $this->authorReceivingEvent($site, $epc);

            // A partner recalling stock we hold is telling us it is gone whether or not
            // we authored the notice, so direction and authored_kind do not gate this.
            $this->authorEvent(
                site: $site,
                epc: $epc,
                bizStep: 'urn:epcglobal:cbv:bizstep:holding',
                disposition: 'urn:epcglobal:cbv:disp:recalled',
                authoredKind: null,
                direction: 'inbound',
                notes: 'Partner recall notice for a terminal disposition custody test.',
            );

            $this->assertFalse(app(EpcCustodyGate::class)->isInCustody($epc->fresh()));
            $this->assertFalse(
                app(ShippableEpcsAtSite::class)->contains((int) $site->getKey(), (int) $epc->getKey()),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function shipping_a_transfer_is_refused_when_a_confirmed_unit_was_destroyed_after_the_scan(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            $epc = $this->createEpc();

            $this->authorReceivingEvent($fromSite, $epc);

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $session->getKey();

            $scan = app(ConfirmTransferringScan::class)->handle($session, (string) $epc->epc_uri);
            $this->assertTrue($scan['ok']);
            $this->assertSame('confirmed', $scan['effect']);

            // The gap the completion recheck exists to close: destroyed between the last
            // scan and the ship, with the line already confirmed.
            $this->authorTerminalEvent($fromSite, $epc, 'urn:epcglobal:cbv:disp:destroyed');

            try {
                app(CompleteTransferringSession::class)->handle($session->fresh());
                $this->fail('Expected shipping a transfer to be refused for a destroyed unit.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('destroyed', $e->getMessage());
            }

            // The refusal rolls the whole ship back: no status change, no transfer EPCIS.
            $session = $session->fresh();
            $this->assertSame('open', (string) $session->status);
            $this->assertNull($session->shipped_at);
            $this->assertNull($session->transfer_events_generated_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function a_unit_that_came_to_rest_elsewhere_is_not_on_hand_at_the_read_point(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$site] = $this->createTransferSites($tenant);
            $epc = $this->createEpc();

            // bizLocation is where the unit came to rest, which is the GLN custody reads
            // ({@see \App\Support\Custody\ResolveEpcLastKnownGln::preferredGln()}), so an
            // event scanned at our dock but resting elsewhere leaves nothing here.
            $this->authorEvent(
                site: $site,
                epc: $epc,
                bizStep: 'urn:epcglobal:cbv:bizstep:receiving',
                disposition: 'urn:epcglobal:cbv:disp:in_progress',
                authoredKind: EpcisAuthoredKind::Receiving,
                notes: 'Receiving EPCIS resting at another location.',
                bizLocationGln: $this->uniqueGln(),
            );

            $this->assertFalse(
                app(ShippableEpcsAtSite::class)->contains((int) $site->getKey(), (int) $epc->getKey()),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * The receiving ObjectEvent GenerateReceivingEpcisEvents authors on receipt,
     * dated before any terminal event so the ordering under test is unambiguous.
     */
    private function authorReceivingEvent(Site $site, Epc $epc): void
    {
        $this->authorEvent(
            site: $site,
            epc: $epc,
            bizStep: 'urn:epcglobal:cbv:bizstep:receiving',
            disposition: 'urn:epcglobal:cbv:disp:in_progress',
            authoredKind: EpcisAuthoredKind::Receiving,
            notes: 'Generated receiving EPCIS for a terminal disposition custody test.',
            minutesAgo: 10,
        );
    }

    /**
     * A decommissioning ObjectEvent: read at our own dock, which is exactly why the
     * GLN cannot be the whole answer.
     */
    private function authorTerminalEvent(Site $site, Epc $epc, string $disposition): void
    {
        $this->authorEvent(
            site: $site,
            epc: $epc,
            bizStep: 'urn:epcglobal:cbv:bizstep:decommissioning',
            disposition: $disposition,
            authoredKind: null,
            notes: 'Decommissioning EPCIS for a terminal disposition custody test.',
        );
    }

    private function authorEvent(
        Site $site,
        Epc $epc,
        string $bizStep,
        string $disposition,
        ?EpcisAuthoredKind $authoredKind,
        string $notes,
        string $direction = 'outbound',
        ?string $bizLocationGln = null,
        int $minutesAgo = 0,
    ): void {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => $direction,
            'authored_kind' => $authoredKind,
            'status' => 'parsed',
            'original_filename' => 'terminal-disposition-'.Str::random(6).'.xml',
            'notes' => $notes,
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => now()->subMinutes($minutesAgo),
            'record_time' => now(),
            'event_timezone_offset' => '+00:00',
            'action' => 'OBSERVE',
            'biz_step' => $bizStep,
            'disposition' => $disposition,
            'read_point_gln' => (string) $site->gln,
            'biz_location_gln' => $bizLocationGln ?? (string) $site->gln,
        ]);
        $this->eventIds[] = (int) $event->getKey();

        DB::table('event_epcs')->insertOrIgnore([[
            'event_id' => $event->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]]);
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createTransferSites(Tenant $tenant): array
    {
        $fromSite = Site::query()->create([
            'name' => 'Terminal From '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $fromSite->getKey();

        $toSite = Site::query()->create([
            'name' => 'Terminal To '.Str::random(6),
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
            .'.TD'.random_int(10000000, 99999999);

        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->epcIds[] = (int) $epc->getKey();

        return $epc;
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
