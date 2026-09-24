<?php

namespace Tests\Feature\Floor;

use App\Actions\Disposition\CompleteDispositionSession;
use App\Actions\Disposition\StageDispositionScan;
use App\Actions\Packing\CompletePackingSession;
use App\Actions\Packing\StagePackingScan;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Actions\Shipping\ConfirmOutboundShippingScan;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Enums\PackingSessionKind;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Disposition\DispositionScanLine;
use App\Models\Disposition\DispositionSession;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Packing\PackingScanLine;
use App\Models\Packing\PackingSession;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Floor\EpcExclusiveSessionGate;
use App\Support\Floor\ExclusiveSessionContext;
use App\Support\Gs1\Gtin;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExclusiveConfirmVsCompleteWorkflowsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $receiveSessionIds = [];

    /** @var list<int> */
    private array $shipSessionIds = [];

    /** @var list<int> */
    private array $packSessionIds = [];

    /** @var list<int> */
    private array $dispositionSessionIds = [];

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

    private ?bool $priorRequireTi = null;

    #[Test]
    public function receive_confirm_skips_reserved_children_and_complete_names_them(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->actingAsOwner();
            $this->disableScanFirstTi($tenant);
            $site = $this->createOrgSite($tenant, 'Receive Exclusive');

            [$parent, $child] = $this->createUnlinkedEpcs();

            $childSession = app(OpenScanFirstReceivingSession::class)->handle((int) $site->getKey());
            $this->receiveSessionIds[] = (int) $childSession->getKey();
            // Scan-first refuses item-level scans; reserve the child on the line table
            // the exclusive gate reads so confirm still only checks the scanned SSCC.
            ReceivingScanLine::query()->create([
                'receiving_session_id' => $childSession->getKey(),
                'epc_id' => $child->getKey(),
                'line_role' => 'child',
                'status' => 'confirmed',
                'scan_raw' => (string) $child->epc_uri,
                'confirmed_at' => now(),
            ]);

            $this->sealChildUnderParent($parent, $child);

            $parentSession = app(OpenScanFirstReceivingSession::class)->handle((int) $site->getKey());
            $this->receiveSessionIds[] = (int) $parentSession->getKey();

            $gate = app(EpcExclusiveSessionGate::class);
            $except = ExclusiveSessionContext::forReceiving($parentSession);
            $this->assertNotNull($gate->check($parent, $except));
            $this->assertNull($gate->checkScannedEpc($parent, $except));

            $parentConfirm = app(ConfirmReceivingScan::class)->handle($parentSession, (string) $parent->epc_uri);
            $this->assertTrue($parentConfirm['ok'], $parentConfirm['message']);

            try {
                $gate->assertParentsHierarchyFree(
                    [(int) $parent->getKey()],
                    ExclusiveSessionContext::forReceiving($parentSession),
                );
                $this->fail('Receive hierarchy assert should name the reserved child.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString((string) ($child->gtin14 ?: $child->epc_uri), $exception->getMessage());
            }

            $this->assertSame('in_progress', (string) $parentSession->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function ship_confirm_skips_reserved_children_and_complete_names_them(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->actingAsOwner();
            $site = $this->createOrgSite($tenant, 'Ship Exclusive');
            $this->setDefaultSites($tenant, $site, $site);

            [$parent, $child] = $this->createUnlinkedEpcs();
            $this->receiveAtSite($site, $parent);
            $this->receiveAtSite($site, $child);

            $childSession = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->shipSessionIds[] = (int) $childSession->getKey();
            $childConfirm = app(ConfirmOutboundShippingScan::class)->handle($childSession, (string) $child->epc_uri);
            $this->assertTrue($childConfirm['ok'], $childConfirm['message']);

            $this->sealChildUnderParent($parent, $child);

            $parentSession = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->shipSessionIds[] = (int) $parentSession->getKey();

            $gate = app(EpcExclusiveSessionGate::class);
            $except = ExclusiveSessionContext::forShipping($parentSession);
            $this->assertNotNull($gate->check($parent, $except));
            $this->assertNull($gate->checkScannedEpc($parent, $except));

            $parentConfirm = app(ConfirmOutboundShippingScan::class)->handle($parentSession, (string) $parent->epc_uri);
            $this->assertTrue($parentConfirm['ok'], $parentConfirm['message']);

            try {
                $gate->assertParentsHierarchyFree(
                    [(int) $parent->getKey()],
                    ExclusiveSessionContext::forShipping($parentSession),
                );
                $this->fail('Ship hierarchy assert should name the reserved child.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString((string) ($child->gtin14 ?: $child->epc_uri), $exception->getMessage());
            }

            $this->assertSame('in_progress', (string) $parentSession->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function pack_stage_skips_reserved_children_and_complete_names_them(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->actingAsOwner();
            $site = $this->createOrgSite($tenant, 'Pack Exclusive');

            [$parent, $child] = $this->createUnlinkedEpcs();

            $childSession = $this->openPackSession($site);
            $childStage = app(StagePackingScan::class)->handle($childSession, (string) $child->epc_uri);
            $this->assertTrue($childStage['ok'], $childStage['message']);

            $this->sealChildUnderParent($parent, $child);

            $parentSession = $this->openPackSession($site);
            $gate = app(EpcExclusiveSessionGate::class);
            $except = ExclusiveSessionContext::forPacking($parentSession);
            $this->assertNotNull($gate->check($parent, $except));
            $this->assertNull($gate->checkScannedEpc($parent, $except));

            $parentStage = app(StagePackingScan::class)->handle($parentSession, (string) $parent->epc_uri, 'parent');
            $this->assertTrue($parentStage['ok'], $parentStage['message']);

            try {
                app(CompletePackingSession::class)->handle($parentSession->fresh());
                $this->fail('Pack complete should block when a staged parent has a reserved child.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString((string) ($child->gtin14 ?: $child->epc_uri), $exception->getMessage());
                $this->assertSame('open', (string) $parentSession->fresh()->status);
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function disposition_stage_skips_reserved_children_and_complete_names_them(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->actingAsOwner();
            $site = $this->createOrgSite($tenant, 'Disposition Exclusive');

            [$parent, $child] = $this->createUnlinkedEpcs();

            $childSession = $this->openDispositionSession($site);
            $childStage = app(StageDispositionScan::class)->handle($childSession, (string) $child->epc_uri);
            $this->assertTrue($childStage['ok'], $childStage['message']);

            $this->sealChildUnderParent($parent, $child);

            $parentSession = $this->openDispositionSession($site);
            $gate = app(EpcExclusiveSessionGate::class);
            $except = ExclusiveSessionContext::forDisposition($parentSession);
            $this->assertNotNull($gate->check($parent, $except));
            $this->assertNull($gate->checkScannedEpc($parent, $except));

            $parentStage = app(StageDispositionScan::class)->handle($parentSession, (string) $parent->epc_uri);
            $this->assertTrue($parentStage['ok'], $parentStage['message']);

            try {
                app(CompleteDispositionSession::class)->handle($parentSession->fresh());
                $this->fail('Disposition complete should block when a staged parent has a reserved child.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString((string) ($child->gtin14 ?: $child->epc_uri), $exception->getMessage());
                $this->assertSame('open', (string) $parentSession->fresh()->status);
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @return array{0: Epc, 1: Epc}
     */
    private function createUnlinkedEpcs(): array
    {
        $parent = Epc::query()->create(Epc::materializeAttributesFromUri($this->uniqueSsccUri()));
        $child = Epc::query()->create(Epc::materializeAttributesFromUri($this->uniqueSgtinUri()));
        $this->epcIds[] = (int) $parent->getKey();
        $this->epcIds[] = (int) $child->getKey();

        return [$parent, $child];
    }

    private function sealChildUnderParent(Epc $parent, Epc $child): void
    {
        $this->aggregationLinkIds[] = (int) AggregationLink::query()->create([
            'parent_epc_id' => $parent->getKey(),
            'child_epc_id' => $child->getKey(),
            'link_type' => 'contains',
            'valid_from' => now(),
            'valid_to' => null,
        ])->getKey();
    }

    private function openPackSession(Site $site): PackingSession
    {
        $session = PackingSession::query()->create([
            'session_kind' => PackingSessionKind::Pack,
            'site_id' => $site->getKey(),
            'status' => 'open',
            'staged_count' => 0,
            'confirmed_count' => 0,
            'opened_at' => now(),
        ]);
        $this->packSessionIds[] = (int) $session->getKey();

        return $session;
    }

    private function openDispositionSession(Site $site): DispositionSession
    {
        $session = DispositionSession::query()->create([
            'biz_step' => 'urn:epcglobal:cbv:bizstep:destroying',
            'site_id' => $site->getKey(),
            'status' => 'open',
            'staged_count' => 0,
            'confirmed_count' => 0,
            'opened_at' => now(),
        ]);
        $this->dispositionSessionIds[] = (int) $session->getKey();

        return $session;
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

    private function createOrgSite(Tenant $tenant, string $prefix): Site
    {
        $site = Site::query()->create([
            'name' => $prefix.' '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $site->getKey();
        $this->setDefaultSites($tenant, $site, $site);

        return $site;
    }

    private function setDefaultSites(Tenant $tenant, Site $from, Site $to): void
    {
        $settings = TenantSettings::forTenant($tenant);
        if ($this->priorDefaultShipFromSiteId === null) {
            $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
            $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        }
        $settings->setDefaultShipFromSiteId((int) $from->getKey());
        $settings->setDefaultReceiveSiteId((int) $to->getKey());
        $tenant->save();
    }

    private function disableScanFirstTi(Tenant $tenant): void
    {
        $settings = TenantSettings::forTenant($tenant);
        $this->priorRequireTi = $settings->requireTiForScanFirst();
        $settings->setRequireTiForScanFirst(false);
        $tenant->save();
    }

    private function actingAsOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $owner = User::factory()->create();
        $owner->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $owner->getKey();
        $this->actingAs($owner);

        return $owner;
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
            'original_filename' => 'exclusive-workflow-custody.xml',
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

        foreach ($this->receiveSessionIds as $sessionId) {
            ReceivingScanLine::query()->where('receiving_session_id', $sessionId)->delete();
            ReceivingSession::query()->whereKey($sessionId)->delete();
        }
        $this->receiveSessionIds = [];

        foreach ($this->shipSessionIds as $sessionId) {
            OutboundShippingScanLine::query()->where('outbound_shipping_session_id', $sessionId)->delete();
            OutboundShippingSession::query()->whereKey($sessionId)->delete();
        }
        $this->shipSessionIds = [];

        foreach ($this->packSessionIds as $sessionId) {
            PackingScanLine::query()->where('packing_session_id', $sessionId)->delete();
            PackingSession::query()->whereKey($sessionId)->delete();
        }
        $this->packSessionIds = [];

        foreach ($this->dispositionSessionIds as $sessionId) {
            DispositionScanLine::query()->where('disposition_session_id', $sessionId)->delete();
            DispositionSession::query()->whereKey($sessionId)->delete();
        }
        $this->dispositionSessionIds = [];

        foreach ($this->aggregationLinkIds as $linkId) {
            AggregationLink::query()->whereKey($linkId)->delete();
        }
        $this->aggregationLinkIds = [];

        foreach ($this->epcIds as $epcId) {
            AggregationLink::query()
                ->where('parent_epc_id', $epcId)
                ->orWhere('child_epc_id', $epcId)
                ->delete();
            ReceivingScanLine::query()->where('epc_id', $epcId)->delete();
            OutboundShippingScanLine::query()->where('epc_id', $epcId)->delete();
            PackingScanLine::query()->where('epc_id', $epcId)->delete();
            DispositionScanLine::query()->where('epc_id', $epcId)->delete();
            DB::table('event_epcs')->where('epc_id', $epcId)->delete();
            Epc::query()->whereKey($epcId)->delete();
        }
        $this->epcIds = [];

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        $settings = TenantSettings::forTenant($tenant);
        $settings->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
        $settings->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
        if ($this->priorRequireTi !== null) {
            $settings->setRequireTiForScanFirst($this->priorRequireTi);
        }
        $tenant->save();

        tenancy()->end();
    }
}
