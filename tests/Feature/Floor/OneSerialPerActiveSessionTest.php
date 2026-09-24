<?php

namespace Tests\Feature\Floor;

use App\Actions\Disposition\CompleteDispositionSession;
use App\Actions\Disposition\DeleteDispositionSession;
use App\Actions\Disposition\OpenDispositionSession;
use App\Actions\Disposition\StageDispositionScan;
use App\Actions\Disposition\UnstageDispositionScanLine;
use App\Actions\Packing\CompletePackingSession;
use App\Actions\Packing\DeletePackingSession;
use App\Actions\Packing\OpenPackingSession;
use App\Actions\Packing\StagePackingScan;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\DeleteReceivingSession;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Actions\Receiving\StageReceivingScan;
use App\Actions\Receiving\UnstageReceivingScanLine;
use App\Enums\PackingSessionKind;
use App\Enums\TenantProfile;
use App\Models\Disposition\DispositionScanLine;
use App\Models\Disposition\DispositionSession;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Packing\PackingScanLine;
use App\Models\Packing\PackingSession;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Support\Floor\EpcExclusiveSessionGate;
use App\Support\Floor\ExclusiveSessionContext;
use App\Support\Floor\OpenFloorWork;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantSettings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OneSerialPerActiveSessionTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $receivingSessionIds = [];

    /** @var list<int> */
    private array $packingSessionIds = [];

    /** @var list<int> */
    private array $dispositionSessionIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $aggregationLinkIds = [];

    private ?bool $priorRequireTi = null;

    #[Test]
    public function staged_receive_blocks_second_session_and_unstage_releases(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $uri = $this->uniqueSgtinUri('ST');
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $sessionA = app(OpenScanFirstReceivingSession::class)->handle();
            $this->receivingSessionIds[] = (int) $sessionA->getKey();

            $staged = app(StageReceivingScan::class)->handle($sessionA, $uri);
            $this->assertTrue($staged['ok'], $staged['message']);
            $this->assertSame('staged', ReceivingScanLine::query()
                ->where('receiving_session_id', $sessionA->getKey())
                ->where('epc_id', $epc->getKey())
                ->value('status'));

            $sessionB = app(OpenScanFirstReceivingSession::class)->handle();
            $this->receivingSessionIds[] = (int) $sessionB->getKey();

            $blockedStage = app(StageReceivingScan::class)->handle($sessionB, $uri);
            $this->assertFalse($blockedStage['ok']);
            $this->assertSame('double_receive', $blockedStage['effect']);

            $blockedConfirm = app(ConfirmReceivingScan::class)->handle($sessionB, $uri);
            $this->assertFalse($blockedConfirm['ok']);
            $this->assertSame('double_receive', $blockedConfirm['effect']);

            $line = ReceivingScanLine::query()
                ->where('receiving_session_id', $sessionA->getKey())
                ->where('epc_id', $epc->getKey())
                ->firstOrFail();
            app(UnstageReceivingScanLine::class)->handle($line);

            $released = app(StageReceivingScan::class)->handle($sessionB, $uri);
            $this->assertTrue($released['ok'], $released['message']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function staged_pack_blocks_receive_until_session_is_deleted_or_completed(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $siteId = $this->resolveEligibleSiteId();
            $this->assertNotNull($siteId);

            $uri = $this->uniqueSgtinUri('PK');
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $pack = app(OpenPackingSession::class)->handle(PackingSessionKind::Pack, $siteId);
            $this->packingSessionIds[] = (int) $pack->getKey();

            $staged = app(StagePackingScan::class)->handle($pack, $uri);
            $this->assertTrue($staged['ok'], $staged['message']);

            $receive = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->receivingSessionIds[] = (int) $receive->getKey();

            $blocked = app(ConfirmReceivingScan::class)->handle($receive, $uri);
            $this->assertFalse($blocked['ok']);
            $this->assertSame('on_open_pack', $blocked['effect']);

            app(DeletePackingSession::class)->handle($pack->fresh());
            $this->packingSessionIds = array_values(array_filter(
                $this->packingSessionIds,
                fn (int $id): bool => $id !== (int) $pack->getKey(),
            ));

            $released = app(ConfirmReceivingScan::class)->handle($receive, $uri);
            $this->assertTrue($released['ok'], $released['message']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function completing_pack_session_releases_reservation(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $siteId = $this->resolveEligibleSiteId();
            $this->assertNotNull($siteId);

            $uri = $this->uniqueSgtinUri('PC');
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $pack = app(OpenPackingSession::class)->handle(PackingSessionKind::Unpack, $siteId);
            $this->packingSessionIds[] = (int) $pack->getKey();
            $this->assertTrue(app(StagePackingScan::class)->handle($pack, $uri)['ok']);

            app(CompletePackingSession::class)->handle($pack->fresh());
            $this->assertNotNull($pack->fresh()->packing_events_generated_at);
            $this->assertSame('confirmed', PackingScanLine::query()
                ->where('packing_session_id', $pack->getKey())
                ->where('epc_id', $epc->getKey())
                ->value('status'));

            $this->assertNull(app(EpcExclusiveSessionGate::class)->check($epc, ExclusiveSessionContext::none()));

            $receive = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->receivingSessionIds[] = (int) $receive->getKey();
            $confirmed = app(ConfirmReceivingScan::class)->handle($receive, $uri);
            $this->assertTrue($confirmed['ok'], $confirmed['message']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function staged_disposition_blocks_receive_until_deleted(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $siteId = $this->resolveEligibleSiteId();
            $this->assertNotNull($siteId);

            $uri = $this->uniqueSgtinUri('DS');
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $disposition = app(OpenDispositionSession::class)->handle('decommissioning', $siteId);
            $this->dispositionSessionIds[] = (int) $disposition->getKey();
            $this->assertTrue(app(StageDispositionScan::class)->handle($disposition, $uri)['ok']);

            $receive = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->receivingSessionIds[] = (int) $receive->getKey();
            $blocked = app(StageReceivingScan::class)->handle($receive, $uri);
            $this->assertFalse($blocked['ok']);
            $this->assertSame('on_open_disposition', $blocked['effect']);

            $line = $disposition->scanLines()->where('epc_id', $epc->getKey())->firstOrFail();
            app(UnstageDispositionScanLine::class)->handle($line);
            app(DeleteDispositionSession::class)->handle($disposition->fresh());
            $this->dispositionSessionIds = [];

            $released = app(StageReceivingScan::class)->handle($receive, $uri);
            $this->assertTrue($released['ok'], $released['message']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function deleting_receive_session_releases_staged_lock(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $uri = $this->uniqueSgtinUri('DL');
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $sessionA = app(OpenScanFirstReceivingSession::class)->handle();
            $this->receivingSessionIds[] = (int) $sessionA->getKey();
            $this->assertTrue(app(StageReceivingScan::class)->handle($sessionA, $uri)['ok']);

            app(DeleteReceivingSession::class)->handle($sessionA->fresh());
            $this->receivingSessionIds = [];

            $sessionB = app(OpenScanFirstReceivingSession::class)->handle();
            $this->receivingSessionIds[] = (int) $sessionB->getKey();
            $released = app(ConfirmReceivingScan::class)->handle($sessionB, $uri);
            $this->assertTrue($released['ok'], $released['message']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function open_work_lists_unsubmitted_sessions_for_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $siteId = $this->resolveEligibleSiteId();
            $this->assertNotNull($siteId);

            $receive = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->receivingSessionIds[] = (int) $receive->getKey();
            $pack = app(OpenPackingSession::class)->handle(PackingSessionKind::Pack, $siteId);
            $this->packingSessionIds[] = (int) $pack->getKey();
            $disposition = app(OpenDispositionSession::class)->handle('returning', $siteId);
            $this->dispositionSessionIds[] = (int) $disposition->getKey();

            $items = OpenFloorWork::itemsForSite($siteId, 10);
            $ids = $items->pluck('id')->all();

            $this->assertContains((int) $receive->getKey(), $ids);
            $this->assertContains((int) $pack->getKey(), $ids);
            $this->assertContains((int) $disposition->getKey(), $ids);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function reserved_parent_blocks_open_aggregation_child(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $siteId = $this->resolveEligibleSiteId();
            $this->assertNotNull($siteId);

            [$parent, $child] = $this->createOpenHierarchy();

            $disposition = app(OpenDispositionSession::class)->handle('decommissioning', $siteId);
            $this->dispositionSessionIds[] = (int) $disposition->getKey();
            $this->assertTrue(app(StageDispositionScan::class)->handle($disposition, (string) $parent->epc_uri)['ok']);

            $pack = app(OpenPackingSession::class)->handle(PackingSessionKind::Pack, $siteId);
            $this->packingSessionIds[] = (int) $pack->getKey();
            $staged = app(StagePackingScan::class)->handle($pack, (string) $child->epc_uri);
            $this->assertTrue($staged['ok'], $staged['message']);

            $gate = app(EpcExclusiveSessionGate::class);
            $except = ExclusiveSessionContext::forPacking($pack);
            $this->assertNotNull($gate->check($child, $except));
            $this->assertNull($gate->checkScannedEpc($child, $except));

            try {
                app(CompletePackingSession::class)->handle($pack->fresh());
                $this->fail('Pack complete should name the reserved parent.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString((string) ($parent->sscc18 ?: $parent->epc_uri), $exception->getMessage());
                $this->assertSame('open', (string) $pack->fresh()->status);
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function reserved_child_blocks_sealed_parent(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $siteId = $this->resolveEligibleSiteId();
            $this->assertNotNull($siteId);

            [$parent, $child] = $this->createOpenHierarchy();

            $childSession = app(OpenDispositionSession::class)->handle('returning', $siteId);
            $this->dispositionSessionIds[] = (int) $childSession->getKey();
            $this->assertTrue(app(StageDispositionScan::class)->handle($childSession, (string) $child->epc_uri)['ok']);

            $parentSession = app(OpenDispositionSession::class)->handle('decommissioning', $siteId);
            $this->dispositionSessionIds[] = (int) $parentSession->getKey();
            $staged = app(StageDispositionScan::class)->handle($parentSession, (string) $parent->epc_uri);
            $this->assertTrue($staged['ok'], $staged['message']);

            $gate = app(EpcExclusiveSessionGate::class);
            $except = ExclusiveSessionContext::forDisposition($parentSession);
            $this->assertNotNull($gate->check($parent, $except));
            $this->assertNull($gate->checkScannedEpc($parent, $except));

            try {
                app(CompleteDispositionSession::class)->handle($parentSession->fresh());
                $this->fail('Disposition complete should name the reserved child.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString((string) ($child->gtin14 ?: $child->epc_uri), $exception->getMessage());
                $this->assertSame('open', (string) $parentSession->fresh()->status);
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function reserved_child_does_not_block_sibling(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $siteId = $this->resolveEligibleSiteId();
            $this->assertNotNull($siteId);

            [$parent, $childA, $childB] = $this->createOpenHierarchy(withSibling: true);

            $reserved = app(OpenDispositionSession::class)->handle('returning', $siteId);
            $this->dispositionSessionIds[] = (int) $reserved->getKey();
            $this->assertTrue(app(StageDispositionScan::class)->handle($reserved, (string) $childA->epc_uri)['ok']);

            $pack = app(OpenPackingSession::class)->handle(PackingSessionKind::Pack, $siteId);
            $this->packingSessionIds[] = (int) $pack->getKey();
            $released = app(StagePackingScan::class)->handle($pack, (string) $childB->epc_uri);
            $this->assertTrue($released['ok'], $released['message']);
            $this->assertNotSame((int) $parent->getKey(), (int) $childB->getKey());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function staging_pack_parent_line_reserves_parent_and_children(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $siteId = $this->resolveEligibleSiteId();
            $this->assertNotNull($siteId);

            [$parent, $child] = $this->createOpenHierarchy();

            $unpack = app(OpenPackingSession::class)->handle(PackingSessionKind::Unpack, $siteId);
            $this->packingSessionIds[] = (int) $unpack->getKey();
            $staged = app(StagePackingScan::class)->handle($unpack, (string) $parent->epc_uri, 'parent');
            $this->assertTrue($staged['ok'], $staged['message']);
            $this->assertSame('parent', PackingScanLine::query()
                ->where('packing_session_id', $unpack->getKey())
                ->where('epc_id', $parent->getKey())
                ->value('line_role'));

            $disposition = app(OpenDispositionSession::class)->handle('decommissioning', $siteId);
            $this->dispositionSessionIds[] = (int) $disposition->getKey();
            $blockedParent = app(StageDispositionScan::class)->handle($disposition, (string) $parent->epc_uri);
            $this->assertFalse($blockedParent['ok']);
            $this->assertSame('on_open_pack', $blockedParent['effect']);

            $childStage = app(StageDispositionScan::class)->handle($disposition, (string) $child->epc_uri);
            $this->assertTrue($childStage['ok'], $childStage['message']);

            try {
                app(CompleteDispositionSession::class)->handle($disposition->fresh());
                $this->fail('Disposition complete should name the reserved pack parent.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString((string) ($parent->sscc18 ?: $parent->epc_uri), $exception->getMessage());
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function completing_disposition_session_releases_reservation(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $siteId = $this->resolveEligibleSiteId();
            $this->assertNotNull($siteId);

            $uri = $this->uniqueSgtinUri('DC');
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $disposition = app(OpenDispositionSession::class)->handle('returning', $siteId);
            $this->dispositionSessionIds[] = (int) $disposition->getKey();
            $this->assertTrue(app(StageDispositionScan::class)->handle($disposition, $uri)['ok']);

            app(CompleteDispositionSession::class)->handle($disposition->fresh());
            $this->assertNull(app(EpcExclusiveSessionGate::class)->check($epc, ExclusiveSessionContext::none()));
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function uniqueSgtinUri(string $prefix): string
    {
        $suffix = (string) random_int(10000000, 99999999);

        return 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.'.$prefix.$suffix;
    }

    private function uniqueSsccUri(string $prefix): string
    {
        $body = str_pad((string) random_int(0, 99_999_999), 9, '0', STR_PAD_LEFT);

        return 'urn:epc:id:sscc:030116.01'.$body;
    }

    /**
     * @return array{0: Epc, 1: Epc, 2?: Epc}
     */
    private function createOpenHierarchy(bool $withSibling = false): array
    {
        $parent = Epc::query()->create(Epc::materializeAttributesFromUri($this->uniqueSsccUri('HP')));
        $childA = Epc::query()->create(Epc::materializeAttributesFromUri($this->uniqueSgtinUri('HA')));
        $this->epcIds[] = (int) $parent->getKey();
        $this->epcIds[] = (int) $childA->getKey();

        $this->aggregationLinkIds[] = (int) AggregationLink::query()->create([
            'parent_epc_id' => $parent->getKey(),
            'child_epc_id' => $childA->getKey(),
            'link_type' => 'contains',
            'valid_from' => now(),
            'valid_to' => null,
        ])->getKey();

        if (! $withSibling) {
            return [$parent, $childA];
        }

        $childB = Epc::query()->create(Epc::materializeAttributesFromUri($this->uniqueSgtinUri('HB')));
        $this->epcIds[] = (int) $childB->getKey();
        $this->aggregationLinkIds[] = (int) AggregationLink::query()->create([
            'parent_epc_id' => $parent->getKey(),
            'child_epc_id' => $childB->getKey(),
            'link_type' => 'contains',
            'valid_from' => now(),
            'valid_to' => null,
        ])->getKey();

        return [$parent, $childA, $childB];
    }

    private function resolveEligibleSiteId(): ?int
    {
        $site = EligibleReceiveSites::forOrganization()->reorder()->orderBy('id')->first();

        return $site !== null ? (int) $site->getKey() : null;
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
        $this->priorRequireTi = TenantSettings::forTenant($tenant)->requireTiForScanFirst();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            foreach ($this->receivingSessionIds as $sessionId) {
                ReceivingScanLine::query()->where('receiving_session_id', $sessionId)->delete();
                ReceivingSession::query()->whereKey($sessionId)->delete();
            }
            $this->receivingSessionIds = [];

            foreach ($this->packingSessionIds as $sessionId) {
                PackingScanLine::query()->where('packing_session_id', $sessionId)->delete();
                PackingSession::query()->whereKey($sessionId)->delete();
            }
            $this->packingSessionIds = [];

            foreach ($this->dispositionSessionIds as $sessionId) {
                DispositionScanLine::query()
                    ->where('disposition_session_id', $sessionId)
                    ->delete();
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
                Epc::query()->whereKey($epcId)->delete();
            }
            $this->epcIds = [];

            if ($this->priorRequireTi !== null) {
                TenantSettings::forTenant($tenant)->setRequireTiForScanFirst($this->priorRequireTi);
                $tenant->save();
                $this->priorRequireTi = null;
            }

            tenancy()->end();
        }
    }
}
