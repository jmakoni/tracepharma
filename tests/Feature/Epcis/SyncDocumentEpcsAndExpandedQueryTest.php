<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\SyncDocumentEpcsFromEvents;
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
use App\Models\Transferring\TransferringSession;
use App\Support\Gs1\Gtin;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SyncDocumentEpcsAndExpandedQueryTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001999801';

    private const CHILD_A_URI = 'urn:epc:id:sgtin:030116.0200116.10000082009901';

    private const CHILD_B_URI = 'urn:epc:id:sgtin:030116.0200116.10000082009902';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    private ?int $sessionId = null;

    private ?int $transferDocumentId = null;

    private ?int $packingEventId = null;

    private ?int $packingDocumentId = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    #[Test]
    public function sync_projects_event_epcs_and_expanded_query_includes_open_children(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$fromSite, $toSite] = $this->createTransferSites($tenant);

            $sscc = Epc::query()->create(Epc::materializeAttributesFromUri(self::SSCC_URI));
            $childA = Epc::query()->create(Epc::materializeAttributesFromUri(self::CHILD_A_URI));
            $childB = Epc::query()->create(Epc::materializeAttributesFromUri(self::CHILD_B_URI));
            $this->epcIds = [(int) $sscc->id, (int) $childA->id, (int) $childB->id];

            $packingDocument = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now()->subHour(),
                'direction' => 'inbound',
                'format' => 'xml',
                'original_filename' => 'packing-source.xml',
                'file_sha256' => hash('sha256', (string) Str::uuid()),
                'payload_disk' => 'local',
                'payload_path' => 'epcis/inbound/packing-'.Str::uuid().'.xml',
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'event_count' => 1,
                'epc_count' => 3,
                'received_at' => now()->subHour(),
                'ingest_generation' => 1,
            ]);
            $this->packingDocumentId = (int) $packingDocument->id;

            $packingEvent = EpcisEvent::query()->create([
                'document_id' => $packingDocument->id,
                'event_id' => 'urn:uuid:'.(string) Str::uuid(),
                'event_type' => 'AggregationEvent',
                'event_time' => now()->subHour(),
                'record_time' => now()->subHour(),
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
                'ingest_generation' => 1,
            ]);
            $this->packingEventId = (int) $packingEvent->id;

            foreach ([$childA, $childB] as $child) {
                AggregationLink::query()->create([
                    'parent_epc_id' => $sscc->id,
                    'child_epc_id' => $child->id,
                    'established_by_event_id' => $packingEvent->id,
                    'link_type' => 'aggregation',
                    'valid_from' => now()->subHour(),
                    'valid_to' => null,
                ]);
            }

            $session = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $fromSite->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->sessionId = (int) $session->getKey();

            $confirm = app(ConfirmTransferringScan::class)->handle($session, self::SSCC_URI);
            $this->assertTrue($confirm['ok']);

            $shipped = app(CompleteTransferringSession::class)->handle($session->fresh());
            $this->transferDocumentId = (int) $shipped->transfer_epcis_document_id;

            $document = EpcisDocument::query()->findOrFail($this->transferDocumentId);

            $this->assertSame(1, (int) $document->epc_count);
            $this->assertSame(
                1,
                (int) DB::table('document_epcs')->where('document_id', $document->id)->count(),
            );
            $this->assertTrue(
                DB::table('document_epcs')
                    ->where('document_id', $document->id)
                    ->where('epc_id', $sscc->id)
                    ->exists(),
            );

            $this->assertSame(1, $document->epcsQuery()->count());
            $this->assertSame(3, $document->epcsExpandedCount());

            $expandedIds = $document->epcsQueryExpanded()->pluck('epcs.id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
            $this->assertSame(
                collect([$sscc->id, $childA->id, $childB->id])->map(fn ($id): int => (int) $id)->sort()->values()->all(),
                $expandedIds,
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function sync_action_backfills_document_epcs_from_events(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $sscc = Epc::query()->create(Epc::materializeAttributesFromUri(
                'urn:epc:id:sscc:030116.01001999802',
            ));
            $this->epcIds[] = (int) $sscc->id;

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'format' => 'xml',
                'original_filename' => 'sync-backfill-test.xml',
                'file_sha256' => hash('sha256', (string) Str::uuid()),
                'payload_disk' => 'local',
                'payload_path' => 'epcis/outbound/sync-backfill-'.Str::uuid().'.xml',
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'event_count' => 1,
                'epc_count' => 1,
                'received_at' => now(),
                'ingest_generation' => 1,
            ]);
            $this->transferDocumentId = (int) $document->id;

            $event = EpcisEvent::query()->create([
                'document_id' => $document->id,
                'event_id' => 'urn:uuid:'.(string) Str::uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => now(),
                'record_time' => now(),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
                'ingest_generation' => 1,
            ]);

            DB::table('event_epcs')->insert([
                'event_id' => $event->id,
                'epc_id' => $sscc->id,
                'role' => 'epcList',
            ]);

            $this->assertSame(0, (int) DB::table('document_epcs')->where('document_id', $document->id)->count());

            $count = app(SyncDocumentEpcsFromEvents::class)->handle($document->fresh());

            $this->assertSame(1, $count);
            $this->assertSame(1, (int) $document->fresh()->epc_count);
            $this->assertSame(
                1,
                (int) DB::table('document_epcs')->where('document_id', $document->id)->where('epc_id', $sscc->id)->count(),
            );
            $this->assertSame(1, $document->fresh()->epcsQuery()->count());
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createTransferSites(Tenant $tenant): array
    {
        $settings = TenantSettings::forTenant($tenant);
        $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();

        $fromGln = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
        $fromGln .= (string) Gtin::checkDigit($fromGln);
        $toGln = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
        $toGln .= (string) Gtin::checkDigit($toGln);

        $from = Site::query()->create([
            'name' => 'Sync Expand From '.Str::random(6),
            'gln' => $fromGln,
            'is_active' => true,
            'is_headquarters' => true,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $to = Site::query()->create([
            'name' => 'Sync Expand To '.Str::random(6),
            'gln' => $toGln,
            'is_active' => true,
            'is_headquarters' => false,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds = [(int) $from->id, (int) $to->id];

        $settings->setDefaultShipFromSiteId((int) $from->id);
        $settings->setDefaultReceiveSiteId((int) $to->id);
        $tenant->save();

        return [$from, $to];
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

        if ($this->transferDocumentId !== null) {
            $document = EpcisDocument::query()->find($this->transferDocumentId);
            if ($document !== null) {
                try {
                    if (filled($document->payload_path)) {
                        Storage::disk((string) ($document->payload_disk ?: 'local'))
                            ->delete((string) $document->payload_path);
                    }
                } catch (\Throwable) {
                }
                $document->delete();
            }
            $this->transferDocumentId = null;
        }

        if ($this->sessionId !== null) {
            TransferringSession::query()->whereKey($this->sessionId)->delete();
            $this->sessionId = null;
        }

        if ($this->packingEventId !== null) {
            AggregationLink::query()->where('established_by_event_id', $this->packingEventId)->delete();
            EpcisEvent::query()->whereKey($this->packingEventId)->delete();
            $this->packingEventId = null;
        }

        if ($this->packingDocumentId !== null) {
            EpcisDocument::query()->whereKey($this->packingDocumentId)->delete();
            $this->packingDocumentId = null;
        }

        foreach ($this->epcIds as $id) {
            AggregationLink::query()
                ->where('parent_epc_id', $id)
                ->orWhere('child_epc_id', $id)
                ->delete();
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->where('epc_id', $id)->delete();
            }
            if (! DB::table('event_epcs')->where('epc_id', $id)->exists()) {
                Epc::query()->whereKey($id)->delete();
            }
        }
        $this->epcIds = [];

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        $tenant = tenant();
        if ($tenant instanceof Tenant) {
            $settings = TenantSettings::forTenant($tenant);
            $settings->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
            $settings->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
        }

        tenancy()->end();
    }
}
