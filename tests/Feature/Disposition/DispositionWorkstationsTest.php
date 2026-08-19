<?php

namespace Tests\Feature\Disposition;

use App\Actions\Disposition\EmitCommissioningEpcisForEpcs;
use App\Actions\Disposition\EmitDecommissioningEpcis;
use App\Actions\Disposition\EmitReturningEpcis;
use App\Enums\TenantProfile;
use App\Filament\App\Pages\CommissionAllWorkstation;
use App\Filament\App\Pages\DecommissionWorkstation;
use App\Filament\App\Pages\ReturnWorkstation;
use App\Models\Epcis\AggregationLink;
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
use App\Services\Custody\EpcCustodyGate;
use App\Support\Disposition\AcquireCommissionEpcLocks;
use App\Support\Disposition\AcquireDecommissionEpcLocks;
use App\Support\Disposition\AcquireReturningEpcLocks;
use App\Support\Epcis\EpcHasCommissioningEvent;
use App\Support\Gs1\Gtin;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DispositionWorkstationsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $shipSessionIds = [];

    /** @var list<int> */
    private array $receivingSessionIds = [];

    /** @var list<int> */
    private array $transferSessionIds = [];

    private ?TenantProfile $priorProfile = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    private bool $capturedOrganization = false;

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    #[Test]
    public function commission_all_authors_commissioning_biz_step_and_marks_epc_commissioned(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->configureOrganization($tenant);
            $site = $this->createSite($tenant);
            $epc = $this->createEpc();
            $this->receiveAtSite($site, $epc);

            $this->assertFalse(app(EpcHasCommissioningEvent::class)->for((int) $epc->getKey()));

            $result = app(EmitCommissioningEpcisForEpcs::class)->handle(
                [(int) $epc->getKey()],
                (int) $site->getKey(),
                ['sync' => true, 'dispatch' => true],
            );

            $this->assertSame(1, $result['commissioned_count']);
            $this->assertSame(0, $result['skipped_count']);
            $this->assertNotNull($result['document']);
            $this->documentIds[] = (int) $result['document']->getKey();
            $this->assertSame((int) $site->getKey(), (int) $result['document']->ship_from_site_id);

            $this->assertTrue(app(EpcHasCommissioningEvent::class)->for((int) $epc->getKey()));

            $event = EpcisEvent::query()
                ->where('document_id', $result['document']->getKey())
                ->where('event_type', 'ObjectEvent')
                ->first();
            $this->assertNotNull($event);
            $this->eventIds[] = (int) $event->getKey();
            $this->assertSame('ADD', $event->action);
            $this->assertStringContainsString('commissioning', (string) $event->biz_step);
            $this->assertStringContainsString('active', (string) $event->disposition);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function decommission_authors_inactive_and_custody_gate_refuses_afterward(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->configureOrganization($tenant);
            $site = $this->createSite($tenant);
            $epc = $this->createEpc();
            $this->receiveAtSite($site, $epc);

            $this->assertTrue(app(EpcCustodyGate::class)->isInCustody($epc->fresh()));
            $this->assertTrue(app(ShippableEpcsAtSite::class)->contains(
                (int) $site->getKey(),
                (int) $epc->getKey(),
            ));

            $result = app(EmitDecommissioningEpcis::class)->handle(
                [(int) $epc->getKey()],
                (int) $site->getKey(),
                ['sync' => true, 'dispatch' => true],
            );

            $this->assertSame(1, $result['decommissioned_count']);
            $this->assertNotNull($result['document']);
            $this->documentIds[] = (int) $result['document']->getKey();
            $this->assertSame((int) $site->getKey(), (int) $result['document']->ship_from_site_id);

            $event = EpcisEvent::query()
                ->where('document_id', $result['document']->getKey())
                ->where('event_type', 'ObjectEvent')
                ->first();
            $this->assertNotNull($event);
            $this->eventIds[] = (int) $event->getKey();
            $this->assertSame('DELETE', $event->action);
            $this->assertStringContainsString('decommissioning', (string) $event->biz_step);
            $this->assertStringContainsString('inactive', (string) $event->disposition);

            $this->assertFalse(app(EpcCustodyGate::class)->isInCustody($epc->fresh()));

            try {
                app(EpcCustodyGate::class)->assertOperableFor($epc->fresh(), 'packing');
                $this->fail('Expected custody gate to refuse a decommissioned unit.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('inactive', $e->getMessage());
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function decommission_closes_open_aggregation_links_in_hierarchy(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->configureOrganization($tenant);
            $site = $this->createSite($tenant);

            $pallet = $this->createSsccEpc('030116', '00000210167');
            $case = $this->createSsccEpc('030116', '00000210168');
            $unit = $this->createEpc();
            $this->receiveAtSite($site, $pallet);
            $seedEventId = $this->eventIds[array_key_last($this->eventIds)] ?? null;
            $this->assertNotNull($seedEventId);

            $palletCaseLink = AggregationLink::query()->create([
                'parent_epc_id' => $pallet->getKey(),
                'child_epc_id' => $case->getKey(),
                'link_type' => 'contains',
                'established_by_event_id' => $seedEventId,
                'valid_from' => now(),
                'valid_to' => null,
            ]);
            $caseUnitLink = AggregationLink::query()->create([
                'parent_epc_id' => $case->getKey(),
                'child_epc_id' => $unit->getKey(),
                'link_type' => 'contains',
                'established_by_event_id' => $seedEventId,
                'valid_from' => now(),
                'valid_to' => null,
            ]);

            $result = app(EmitDecommissioningEpcis::class)->handle(
                [(int) $pallet->getKey()],
                (int) $site->getKey(),
                ['sync' => true, 'dispatch' => true],
            );

            $this->documentIds[] = (int) $result['document']->getKey();
            $this->assertSame(0, $result['drift_count']);
            $this->assertNull($result['drift_notes']);

            $this->assertNotNull($palletCaseLink->fresh()?->valid_to);
            $this->assertNotNull($caseUnitLink->fresh()?->valid_to);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function return_authors_returning_returned_and_unit_remains_shippable_at_site(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::Pharmacy);
            $this->configureOrganization($tenant);
            $site = $this->createSite($tenant);
            $epc = $this->createEpc();
            $this->receiveAtSite($site, $epc);

            $result = app(EmitReturningEpcis::class)->handle(
                [(int) $epc->getKey()],
                (int) $site->getKey(),
                ['sync' => true, 'dispatch' => true],
            );

            $this->assertSame(1, $result['returned_count']);
            $this->assertNotNull($result['document']);
            $this->documentIds[] = (int) $result['document']->getKey();

            $event = EpcisEvent::query()
                ->where('document_id', $result['document']->getKey())
                ->where('event_type', 'ObjectEvent')
                ->first();
            $this->assertNotNull($event);
            $this->eventIds[] = (int) $event->getKey();
            $this->assertSame('OBSERVE', $event->action);
            $this->assertStringContainsString('returning', (string) $event->biz_step);
            $this->assertStringContainsString('returned', (string) $event->disposition);

            $this->assertTrue(app(EpcCustodyGate::class)->isInCustody($epc->fresh()));
            $this->assertTrue(app(ShippableEpcsAtSite::class)->contains(
                (int) $site->getKey(),
                (int) $epc->getKey(),
            ));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function decommissioning_event_does_not_block_recommission(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->configureOrganization($tenant);
            $site = $this->createSite($tenant);
            $epc = $this->createEpc();

            // Historical decommissioning must not be treated as commissioning.
            $this->seedDispositionEvent(
                $site,
                $epc,
                action: 'DELETE',
                bizStep: 'urn:epcglobal:cbv:bizstep:decommissioning',
                disposition: 'urn:epcglobal:cbv:disp:inactive',
                eventTime: now()->subMinutes(10),
            );
            $this->receiveAtSite($site, $epc);

            $this->assertFalse(app(EpcHasCommissioningEvent::class)->for((int) $epc->getKey()));

            $result = app(EmitCommissioningEpcisForEpcs::class)->handle(
                [(int) $epc->getKey()],
                (int) $site->getKey(),
                ['sync' => true, 'dispatch' => true],
            );

            $this->assertSame(1, $result['commissioned_count']);
            $this->assertSame(0, $result['skipped_count']);
            $this->assertNotNull($result['document']);
            $this->documentIds[] = (int) $result['document']->getKey();
            $this->assertTrue(app(EpcHasCommissioningEvent::class)->for((int) $epc->getKey()));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function overlapping_commission_sets_serialize_on_shared_epc_and_do_not_duplicate(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->configureOrganization($tenant);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'l3_enabled' => false,
                'l3_endpoint_url' => null,
            ]);
            Http::fake();
            $site = $this->createSite($tenant);
            $epcA = $this->createEpc();
            $epcB = $this->createEpc();
            $epcC = $this->createEpc();
            $this->receiveAtSite($site, $epcA);
            $this->receiveAtSite($site, $epcB);
            $this->receiveAtSite($site, $epcC);

            $sharedId = (int) $epcB->getKey();
            $this->app->instance(
                AcquireCommissionEpcLocks::class,
                new AcquireCommissionEpcLocks(ttlSeconds: 60, waitSeconds: 1),
            );

            $held = Cache::lock(AcquireCommissionEpcLocks::key((string) $tenant->getKey(), $sharedId), 60);
            $this->assertTrue($held->get());

            try {
                app(EmitCommissioningEpcisForEpcs::class)->handle(
                    [$sharedId, (int) $epcC->getKey()],
                    (int) $site->getKey(),
                    ['sync' => true, 'dispatch' => false],
                );
                $this->fail('Expected overlapping commission to wait on the shared EPC lock.');
            } catch (LockTimeoutException) {
                // Serializes: the shared EPC lock is held by another commission set.
            } finally {
                $held->release();
            }

            $first = app(EmitCommissioningEpcisForEpcs::class)->handle(
                [(int) $epcA->getKey(), $sharedId],
                (int) $site->getKey(),
                ['sync' => true, 'dispatch' => false],
            );
            $this->assertSame(2, $first['commissioned_count']);
            $this->assertNotNull($first['document']);
            $this->documentIds[] = (int) $first['document']->getKey();

            $second = app(EmitCommissioningEpcisForEpcs::class)->handle(
                [$sharedId, (int) $epcC->getKey()],
                (int) $site->getKey(),
                ['sync' => true, 'dispatch' => false],
            );
            $this->assertSame(1, $second['commissioned_count']);
            $this->assertSame(1, $second['skipped_count']);
            $this->assertNotNull($second['document']);
            $this->documentIds[] = (int) $second['document']->getKey();

            $commissioningEventCount = EpcisEvent::query()
                ->where('event_type', 'ObjectEvent')
                ->where('action', 'ADD')
                ->where('biz_step', 'like', '%commissioning%')
                ->whereIn('document_id', $this->documentIds)
                ->whereHas('epcs', fn ($q) => $q->whereKey($sharedId))
                ->count();

            $this->assertSame(1, $commissioningEventCount);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function overlapping_decommission_sets_serialize_on_shared_epc(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->configureOrganization($tenant);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'l3_enabled' => false,
                'l3_endpoint_url' => null,
            ]);
            Http::fake();
            $site = $this->createSite($tenant);
            $epcA = $this->createEpc();
            $epcB = $this->createEpc();
            $epcC = $this->createEpc();
            $this->receiveAtSite($site, $epcA);
            $this->receiveAtSite($site, $epcB);
            $this->receiveAtSite($site, $epcC);

            $sharedId = (int) $epcB->getKey();
            $this->app->instance(
                AcquireDecommissionEpcLocks::class,
                new AcquireDecommissionEpcLocks(ttlSeconds: 60, waitSeconds: 1),
            );

            $held = Cache::lock(AcquireDecommissionEpcLocks::key((string) $tenant->getKey(), $sharedId), 60);
            $this->assertTrue($held->get());

            try {
                app(EmitDecommissioningEpcis::class)->handle(
                    [$sharedId, (int) $epcC->getKey()],
                    (int) $site->getKey(),
                    ['sync' => true, 'dispatch' => false],
                );
                $this->fail('Expected overlapping decommission to wait on the shared EPC lock.');
            } catch (LockTimeoutException) {
                // Serializes: the shared EPC lock is held by another decommission set.
            } finally {
                $held->release();
            }

            $first = app(EmitDecommissioningEpcis::class)->handle(
                [(int) $epcA->getKey(), $sharedId],
                (int) $site->getKey(),
                ['sync' => true, 'dispatch' => false],
            );
            $this->assertSame(2, $first['decommissioned_count']);
            $this->assertNotNull($first['document']);
            $this->documentIds[] = (int) $first['document']->getKey();

            try {
                app(EmitDecommissioningEpcis::class)->handle(
                    [$sharedId],
                    (int) $site->getKey(),
                    ['sync' => true, 'dispatch' => false],
                );
                $this->fail('Expected second decommission of an already inactive unit to fail closed.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('inactive', strtolower($e->getMessage()));
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function commission_refuses_epc_not_on_hand_at_site(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->configureOrganization($tenant);
            $site = $this->createSite($tenant);
            $epc = $this->createEpc();

            try {
                app(EmitCommissioningEpcisForEpcs::class)->handle(
                    [(int) $epc->getKey()],
                    (int) $site->getKey(),
                    ['sync' => true, 'dispatch' => true],
                );
                $this->fail('Expected commission to refuse an EPC that is not on hand.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('not on hand', $e->getMessage());
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function overlapping_return_sets_serialize_on_shared_epc(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::Pharmacy);
            $this->configureOrganization($tenant);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'l3_enabled' => false,
                'l3_endpoint_url' => null,
            ]);
            Http::fake();
            $site = $this->createSite($tenant);
            $epcA = $this->createEpc();
            $epcB = $this->createEpc();
            $epcC = $this->createEpc();
            $this->receiveAtSite($site, $epcA);
            $this->receiveAtSite($site, $epcB);
            $this->receiveAtSite($site, $epcC);

            $sharedId = (int) $epcB->getKey();
            $this->app->instance(
                AcquireReturningEpcLocks::class,
                new AcquireReturningEpcLocks(ttlSeconds: 60, waitSeconds: 1),
            );

            $held = Cache::lock(AcquireReturningEpcLocks::key((string) $tenant->getKey(), $sharedId), 60);
            $this->assertTrue($held->get());

            try {
                app(EmitReturningEpcis::class)->handle(
                    [$sharedId, (int) $epcC->getKey()],
                    (int) $site->getKey(),
                    ['sync' => true, 'dispatch' => false],
                );
                $this->fail('Expected overlapping return to wait on the shared EPC lock.');
            } catch (LockTimeoutException) {
                // Serializes: the shared EPC lock is held by another return set.
            } finally {
                $held->release();
            }

            $first = app(EmitReturningEpcis::class)->handle(
                [(int) $epcA->getKey(), $sharedId],
                (int) $site->getKey(),
                ['sync' => true, 'dispatch' => false],
            );
            $this->assertSame(2, $first['returned_count']);
            $this->assertNotNull($first['document']);
            $this->documentIds[] = (int) $first['document']->getKey();
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function return_refuses_epc_on_open_ship_order(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::Pharmacy);
            $this->configureOrganization($tenant);
            Http::fake();
            $site = $this->createSite($tenant);
            $epc = $this->createEpc();
            $this->receiveAtSite($site, $epc);

            $shipSession = OutboundShippingSession::query()->create([
                'site_id' => $site->getKey(),
                'status' => 'open',
                'expected_count' => 0,
                'confirmed_count' => 1,
                'dscsa_affirm' => false,
                'opened_at' => now(),
            ]);
            $this->shipSessionIds[] = (int) $shipSession->getKey();

            OutboundShippingScanLine::query()->create([
                'outbound_shipping_session_id' => $shipSession->getKey(),
                'epc_id' => $epc->getKey(),
                'line_role' => 'parent',
                'status' => 'confirmed',
                'scan_raw' => (string) $epc->epc_uri,
                'confirmed_at' => now(),
            ]);

            try {
                app(EmitReturningEpcis::class)->handle(
                    [(int) $epc->getKey()],
                    (int) $site->getKey(),
                    ['sync' => true, 'dispatch' => false],
                );
                $this->fail('Expected return to refuse an EPC on an open ship order.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('open ship order', strtolower($e->getMessage()));
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function return_refuses_epc_on_open_receive_session(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::Pharmacy);
            $this->configureOrganization($tenant);
            Http::fake();
            $site = $this->createSite($tenant);
            $epc = $this->createEpc();
            $this->receiveAtSite($site, $epc);

            $receiveSession = ReceivingSession::query()->create([
                'site_id' => $site->getKey(),
                'status' => 'open',
                'session_kind' => 'scan_first',
                'expected_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_parent_count' => 1,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
            ]);
            $this->receivingSessionIds[] = (int) $receiveSession->getKey();

            ReceivingScanLine::query()->create([
                'receiving_session_id' => $receiveSession->getKey(),
                'epc_id' => $epc->getKey(),
                'line_role' => 'child',
                'status' => 'confirmed',
                'scan_raw' => (string) $epc->epc_uri,
                'confirmed_at' => now(),
            ]);

            try {
                app(EmitReturningEpcis::class)->handle(
                    [(int) $epc->getKey()],
                    (int) $site->getKey(),
                    ['sync' => true, 'dispatch' => false],
                );
                $this->fail('Expected return to refuse an EPC on an open receive session.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('open receive session', strtolower($e->getMessage()));
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function decommission_refuses_epc_on_open_ship_order(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->configureOrganization($tenant);
            Http::fake();
            $site = $this->createSite($tenant);
            $epc = $this->createEpc();
            $this->receiveAtSite($site, $epc);

            $shipSession = OutboundShippingSession::query()->create([
                'site_id' => $site->getKey(),
                'status' => 'open',
                'expected_count' => 0,
                'confirmed_count' => 1,
                'dscsa_affirm' => false,
                'opened_at' => now(),
            ]);
            $this->shipSessionIds[] = (int) $shipSession->getKey();

            OutboundShippingScanLine::query()->create([
                'outbound_shipping_session_id' => $shipSession->getKey(),
                'epc_id' => $epc->getKey(),
                'line_role' => 'parent',
                'status' => 'confirmed',
                'scan_raw' => (string) $epc->epc_uri,
                'confirmed_at' => now(),
            ]);

            try {
                app(EmitDecommissioningEpcis::class)->handle(
                    [(int) $epc->getKey()],
                    (int) $site->getKey(),
                    ['sync' => true, 'dispatch' => false],
                );
                $this->fail('Expected decommission to refuse an EPC on an open ship order.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('open ship order', strtolower($e->getMessage()));
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function decommission_refuses_epc_on_open_transfer_session(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->configureOrganization($tenant);
            Http::fake();
            $site = $this->createSite($tenant);
            $toSite = $this->createSite($tenant);
            $epc = $this->createEpc();
            $this->receiveAtSite($site, $epc);

            $transferSession = TransferringSession::query()->create([
                'from_site_id' => $site->getKey(),
                'to_site_id' => $toSite->getKey(),
                'status' => 'open',
                'expected_count' => 0,
                'confirmed_count' => 1,
                'opened_at' => now(),
            ]);
            $this->transferSessionIds[] = (int) $transferSession->getKey();

            TransferringScanLine::query()->create([
                'transferring_session_id' => $transferSession->getKey(),
                'epc_id' => $epc->getKey(),
                'line_role' => 'child',
                'status' => 'confirmed',
                'scan_raw' => (string) $epc->epc_uri,
                'confirmed_at' => now(),
            ]);

            try {
                app(EmitDecommissioningEpcis::class)->handle(
                    [(int) $epc->getKey()],
                    (int) $site->getKey(),
                    ['sync' => true, 'dispatch' => false],
                );
                $this->fail('Expected decommission to refuse an EPC on an open transfer session.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('open or in-transit transfer', strtolower($e->getMessage()));
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function decommission_refuses_epc_on_open_receive_session(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->configureOrganization($tenant);
            Http::fake();
            $site = $this->createSite($tenant);
            $epc = $this->createEpc();
            $this->receiveAtSite($site, $epc);

            $receiveSession = ReceivingSession::query()->create([
                'site_id' => $site->getKey(),
                'status' => 'open',
                'session_kind' => 'scan_first',
                'expected_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_parent_count' => 1,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
            ]);
            $this->receivingSessionIds[] = (int) $receiveSession->getKey();

            ReceivingScanLine::query()->create([
                'receiving_session_id' => $receiveSession->getKey(),
                'epc_id' => $epc->getKey(),
                'line_role' => 'child',
                'status' => 'confirmed',
                'scan_raw' => (string) $epc->epc_uri,
                'confirmed_at' => now(),
            ]);

            try {
                app(EmitDecommissioningEpcis::class)->handle(
                    [(int) $epc->getKey()],
                    (int) $site->getKey(),
                    ['sync' => true, 'dispatch' => false],
                );
                $this->fail('Expected decommission to refuse an EPC on an open receive session.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('open receive session', strtolower($e->getMessage()));
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function return_refuses_epc_not_on_hand_at_site(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::Pharmacy);
            $this->configureOrganization($tenant);
            $siteA = $this->createSite($tenant);
            $siteB = $this->createSite($tenant);
            $epc = $this->createEpc();
            $this->receiveAtSite($siteA, $epc);

            try {
                app(EmitReturningEpcis::class)->handle(
                    [(int) $epc->getKey()],
                    (int) $siteB->getKey(),
                    ['sync' => true, 'dispatch' => true],
                );
                $this->fail('Expected return to refuse an EPC that is not on hand at the selected site.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('not on hand', $e->getMessage());
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function livewire_can_access_gates_pharmacy_return_not_commission_decommission(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::Pharmacy);
            $this->assertTrue(TenantFeatures::forTenant($tenant)->supportsReturning());
            $this->assertFalse(TenantFeatures::forTenant($tenant)->supportsCommissioning());
            $this->assertTrue(ReturnWorkstation::canAccess());
            $this->assertFalse(CommissionAllWorkstation::canAccess());
            $this->assertFalse(DecommissionWorkstation::canAccess());

            $this->setProfile($tenant, TenantProfile::Manufacturer);
            $this->assertTrue(TenantFeatures::forTenant($tenant)->supportsCommissioning());
            $this->assertTrue(TenantFeatures::forTenant($tenant)->supportsReturning());
            $this->assertTrue(CommissionAllWorkstation::canAccess());
            $this->assertTrue(DecommissionWorkstation::canAccess());
            $this->assertTrue(ReturnWorkstation::canAccess());

            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->assertTrue(CommissionAllWorkstation::canAccess());
            $this->assertTrue(DecommissionWorkstation::canAccess());
            $this->assertTrue(ReturnWorkstation::canAccess());
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function configureOrganization(Tenant $tenant): void
    {
        if (! $this->capturedOrganization) {
            $this->priorGln = $tenant->gln;
            $this->priorCompanyPrefix = $tenant->company_prefix;
            $this->capturedOrganization = true;
        }

        TenantSettings::forTenant($tenant)->saveOrganization([
            'gln' => '0399991000008',
            'company_prefix' => '0399991',
            'l3_enabled' => false,
            'l3_endpoint_url' => null,
        ]);
    }

    private function createSite(Tenant $tenant): Site
    {
        $settings = TenantSettings::forTenant($tenant);
        if ($this->priorDefaultShipFromSiteId === null) {
            $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
            $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        }

        $site = Site::query()->create([
            'name' => 'Disposition Site '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
        ]);
        $this->siteIds[] = (int) $site->getKey();
        $settings->setDefaultShipFromSiteId((int) $site->getKey());
        $settings->setDefaultReceiveSiteId((int) $site->getKey());
        $tenant->save();

        return $site;
    }

    private function createEpc(): Epc
    {
        $serial = (string) random_int(100000000, 999999999);
        $uri = 'urn:epc:id:sgtin:0399991.000001.'.$serial;

        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->epcIds[] = (int) $epc->getKey();

        return $epc;
    }

    private function createSsccEpc(string $companyPrefix, string $serialReference): Epc
    {
        $uri = 'urn:epc:id:sscc:'.$companyPrefix.'.'.$serialReference;
        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->epcIds[] = (int) $epc->getKey();

        return $epc;
    }

    private function receiveAtSite(Site $site, Epc $epc): void
    {
        $this->seedDispositionEvent(
            $site,
            $epc,
            action: 'OBSERVE',
            bizStep: 'urn:epcglobal:cbv:bizstep:receiving',
            disposition: 'urn:epcglobal:cbv:disp:in_progress',
            eventTime: now()->subMinute(),
        );
    }

    private function seedDispositionEvent(
        Site $site,
        Epc $epc,
        string $action,
        string $bizStep,
        string $disposition,
        \DateTimeInterface $eventTime,
    ): void {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'disposition-seed-'.Str::random(6).'.xml',
            'notes' => 'Seeded disposition event for workstation test.',
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => $eventTime,
            'record_time' => $eventTime,
            'event_timezone_offset' => '+00:00',
            'action' => $action,
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
        $prefix = TenantSettings::forTenant(tenant())->companyPrefix() ?: '0399991';
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

        $this->priorProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);
        $this->priorGln = $tenant->gln;
        $this->priorCompanyPrefix = $tenant->company_prefix;

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

    private function setProfile(Tenant $tenant, TenantProfile $profile): void
    {
        $tenant->forceFill(['profile' => $profile])->save();
        $tenant->refresh();
    }

    private function cleanup(Tenant $tenant): void
    {
        if ($this->eventIds !== []) {
            DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
            EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
            $this->eventIds = [];
        }

        if ($this->documentIds !== []) {
            $eventIds = EpcisEvent::query()
                ->whereIn('document_id', $this->documentIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            if ($eventIds !== []) {
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                EpcisEvent::query()->whereIn('id', $eventIds)->delete();
            }
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->epcIds !== []) {
            DB::table('event_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            if (DB::getSchemaBuilder()->hasTable('document_epcs')) {
                DB::table('document_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            }
            if (DB::getSchemaBuilder()->hasTable('epc_ilmd')) {
                DB::table('epc_ilmd')->whereIn('epc_id', $this->epcIds)->delete();
            }
            AggregationLink::query()
                ->whereIn('parent_epc_id', $this->epcIds)
                ->orWhereIn('child_epc_id', $this->epcIds)
                ->delete();
            Epc::query()->whereIn('id', $this->epcIds)->delete();
            $this->epcIds = [];
        }

        if ($this->shipSessionIds !== []) {
            OutboundShippingScanLine::query()
                ->whereIn('outbound_shipping_session_id', $this->shipSessionIds)
                ->delete();
            OutboundShippingSession::query()->whereIn('id', $this->shipSessionIds)->delete();
            $this->shipSessionIds = [];
        }

        if ($this->receivingSessionIds !== []) {
            ReceivingScanLine::query()
                ->whereIn('receiving_session_id', $this->receivingSessionIds)
                ->delete();
            ReceivingSession::query()->whereIn('id', $this->receivingSessionIds)->delete();
            $this->receivingSessionIds = [];
        }

        if ($this->transferSessionIds !== []) {
            TransferringScanLine::query()
                ->whereIn('transferring_session_id', $this->transferSessionIds)
                ->delete();
            TransferringSession::query()->whereIn('id', $this->transferSessionIds)->delete();
            $this->transferSessionIds = [];
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        if ($this->priorDefaultShipFromSiteId !== null) {
            TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
        }
        if ($this->priorDefaultReceiveSiteId !== null) {
            TenantSettings::forTenant($tenant)->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
        }

        if ($this->capturedOrganization) {
            // Prefer forceFill so null priors restore cleanly (saveOrganization validates pairs).
            $tenant->forceFill([
                'gln' => $this->priorGln,
                'company_prefix' => $this->priorCompanyPrefix,
            ])->save();
        }

        if ($this->priorProfile !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile])->save();
        }

        $this->priorDefaultShipFromSiteId = null;
        $this->priorDefaultReceiveSiteId = null;
        $this->priorGln = null;
        $this->priorCompanyPrefix = null;
        $this->priorProfile = null;
        $this->capturedOrganization = false;

        tenancy()->end();
    }
}
