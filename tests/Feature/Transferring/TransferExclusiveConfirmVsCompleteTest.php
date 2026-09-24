<?php

namespace Tests\Feature\Transferring;

use App\Actions\Transferring\CompleteTransferringSession;
use App\Actions\Transferring\ConfirmTransferringScan;
use App\Actions\Transferring\OpenTransferringSession;
use App\Enums\TenantProfile;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Support\Floor\EpcExclusiveSessionGate;
use App\Support\Floor\ExclusiveSessionContext;
use App\Support\Gs1\Gtin;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransferExclusiveConfirmVsCompleteTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $aggregationLinkIds = [];

    /** @var list<int> */
    private array $custodyDocumentIds = [];

    /** @var list<int> */
    private array $custodyEventIds = [];

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    #[Test]
    public function scanned_sscc_already_on_another_session_rejects_at_confirm(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            [$parent] = $this->createOpenHierarchy();
            $this->receiveAtSite($fromSite, $parent);

            $sessionA = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $sessionA->getKey();

            $first = app(ConfirmTransferringScan::class)->handle($sessionA, (string) $parent->epc_uri);
            $this->assertTrue($first['ok'], $first['message']);

            $sessionB = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $sessionB->getKey();

            $second = app(ConfirmTransferringScan::class)->handle($sessionB, (string) $parent->epc_uri);
            $this->assertFalse($second['ok']);
            $this->assertSame('double_transfer', $second['effect']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function confirm_of_sscc_does_not_walk_descendants_when_child_is_reserved(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            [$parent, $child] = $this->createHierarchyAfterReservingChild($fromSite, $toSite);

            $parentSession = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $parentSession->getKey();

            $gate = app(EpcExclusiveSessionGate::class);
            $this->assertNotNull($gate->check($parent, ExclusiveSessionContext::forTransferring($parentSession)));
            $this->assertNull($gate->checkScannedEpc($parent, ExclusiveSessionContext::forTransferring($parentSession)));

            $parentConfirm = app(ConfirmTransferringScan::class)->handle($parentSession, (string) $parent->epc_uri);
            $this->assertTrue($parentConfirm['ok'], $parentConfirm['message']);
            $this->assertSame('confirmed', $parentConfirm['effect']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function reserved_child_blocks_complete_and_names_the_epc(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);
            [$parent, $child] = $this->createHierarchyAfterReservingChild($fromSite, $toSite);

            $parentSession = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionIds[] = (int) $parentSession->getKey();
            $this->assertTrue(
                app(ConfirmTransferringScan::class)->handle($parentSession, (string) $parent->epc_uri)['ok'],
            );

            try {
                app(CompleteTransferringSession::class)->handle($parentSession->fresh());
                $this->fail('Complete should block when a confirmed parent has a reserved child.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString((string) ($child->gtin14 ?: $child->epc_uri), $exception->getMessage());
                $this->assertSame('open', (string) $parentSession->fresh()->status);
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * Reserve the child on another transfer first so open-parent hierarchy
     * does not block that confirm, then seal it under the SSCC.
     *
     * @return array{0: Epc, 1: Epc}
     */
    private function createHierarchyAfterReservingChild(Site $fromSite, Site $toSite): array
    {
        $parent = Epc::query()->create(Epc::materializeAttributesFromUri($this->uniqueSsccUri()));
        $child = Epc::query()->create(Epc::materializeAttributesFromUri($this->uniqueSgtinUri()));
        $this->epcIds[] = (int) $parent->getKey();
        $this->epcIds[] = (int) $child->getKey();
        $this->receiveAtSite($fromSite, $parent);
        $this->receiveAtSite($fromSite, $child);

        $childSession = app(OpenTransferringSession::class)->handle(
            fromSiteId: (int) $fromSite->getKey(),
            toSiteId: (int) $toSite->getKey(),
        );
        $this->sessionIds[] = (int) $childSession->getKey();
        $childConfirm = app(ConfirmTransferringScan::class)->handle($childSession, (string) $child->epc_uri);
        $this->assertTrue($childConfirm['ok'], $childConfirm['message']);

        $this->aggregationLinkIds[] = (int) AggregationLink::query()->create([
            'parent_epc_id' => $parent->getKey(),
            'child_epc_id' => $child->getKey(),
            'link_type' => 'contains',
            'valid_from' => now(),
            'valid_to' => null,
        ])->getKey();

        return [$parent, $child];
    }

    /**
     * @return array{0: Epc}
     */
    private function createOpenHierarchy(): array
    {
        $parent = Epc::query()->create(Epc::materializeAttributesFromUri($this->uniqueSsccUri()));
        $this->epcIds[] = (int) $parent->getKey();

        return [$parent];
    }

    private function uniqueSgtinUri(): string
    {
        $suffix = (string) random_int(10000000, 99999999);

        return 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.XC'.$suffix;
    }

    private function uniqueSsccUri(): string
    {
        $body = str_pad((string) random_int(0, 99_999_999), 9, '0', STR_PAD_LEFT);

        return 'urn:epc:id:sscc:030116.01'.$body;
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createTransferSites(Tenant $tenant): array
    {
        $fromSite = Site::query()->create([
            'name' => 'Transfer From '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $fromSite->getKey();

        $toSite = Site::query()->create([
            'name' => 'Transfer To '.Str::random(6),
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

    private function receiveAtSite(Site $site, Epc $epc): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'transfer-exclusive-custody.xml',
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
        if (! tenancy()->initialized) {
            return;
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

        foreach ($this->sessionIds as $sessionId) {
            TransferringScanLine::query()->where('transferring_session_id', $sessionId)->delete();
            TransferringSession::query()->whereKey($sessionId)->delete();
        }
        $this->sessionIds = [];

        foreach ($this->aggregationLinkIds as $linkId) {
            AggregationLink::query()->whereKey($linkId)->delete();
        }
        $this->aggregationLinkIds = [];

        foreach ($this->epcIds as $epcId) {
            AggregationLink::query()
                ->where('parent_epc_id', $epcId)
                ->orWhere('child_epc_id', $epcId)
                ->delete();
            TransferringScanLine::query()->where('epc_id', $epcId)->delete();
            DB::table('event_epcs')->where('epc_id', $epcId)->delete();
            Epc::query()->whereKey($epcId)->delete();
        }
        $this->epcIds = [];

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        $settings = TenantSettings::forTenant($tenant);
        $settings->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
        $settings->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
        $tenant->save();

        tenancy()->end();
    }
}
