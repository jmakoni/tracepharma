<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\ArchiveAgedEpcisEvents;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Tenant;
use App\Support\Epcis\AuditPedigreePayloadRetention;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PedigreePayloadRetentionTest extends TestCase
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

    #[Test]
    public function payload_retention_years_never_below_event_retention(): void
    {
        config([
            'tracepharma.epcis.retention_years' => 6,
            'tracepharma.epcis.payload_retention_years' => 3,
        ]);

        $years = app(AuditPedigreePayloadRetention::class)->payloadRetentionYears();

        $this->assertSame(6, $years);
        $this->assertSame('whole_event', config('tracepharma.epcis.outbound_pedigree_replay'));
    }

    #[Test]
    public function reports_missing_payload_for_commission_source_document(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $epc = $this->createSgtinEpc();
            $document = EpcisDocument::query()->create([
                'direction' => 'inbound',
                'status' => 'validated',
                'schema_version' => '1.2',
                'document_uuid' => (string) Str::uuid(),
                'payload_disk' => 'local',
                'payload_path' => 'epcis/missing-pedigree-'.Str::random(8).'.xml',
                'original_filename' => 'missing.xml',
                'received_at' => now(),
                'creation_date' => now(),
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $event = EpcisEvent::query()->create([
                'document_id' => $document->getKey(),
                'event_type' => 'ObjectEvent',
                'event_time' => now(),
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                'disposition' => 'urn:epcglobal:cbv:disp:active',
            ]);
            $this->eventIds[] = (int) $event->getKey();

            DB::table('event_epcs')->insert([
                'event_id' => $event->getKey(),
                'epc_id' => $epc->getKey(),
                'role' => 'epcList',
            ]);

            $missing = app(AuditPedigreePayloadRetention::class)->missingPedigreePayloads();
            $ids = array_column($missing, 'document_id');

            $this->assertContains((int) $document->getKey(), $ids);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function event_archive_leaves_document_payload_pointer_intact(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.epcis.retention_years' => 1]);
            $disk = 'local';
            $path = 'epcis/pedigree-keep-'.Str::random(8).'.xml';
            Storage::disk($disk)->put($path, '<epcis/>');

            $epc = $this->createSgtinEpc();
            $document = EpcisDocument::query()->create([
                'direction' => 'inbound',
                'status' => 'validated',
                'schema_version' => '1.2',
                'document_uuid' => (string) Str::uuid(),
                'payload_disk' => $disk,
                'payload_path' => $path,
                'original_filename' => 'keep.xml',
                'received_at' => now()->subYears(2),
                'creation_date' => now()->subYears(2),
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $event = EpcisEvent::query()->create([
                'document_id' => $document->getKey(),
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subYears(2),
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                'disposition' => 'urn:epcglobal:cbv:disp:active',
            ]);
            $this->eventIds[] = (int) $event->getKey();

            DB::table('event_epcs')->insert([
                'event_id' => $event->getKey(),
                'epc_id' => $epc->getKey(),
                'role' => 'epcList',
            ]);

            app(ArchiveAgedEpcisEvents::class)->handle();

            $document->refresh();
            $this->assertSame($path, $document->payload_path);
            $this->assertTrue(Storage::disk($disk)->exists($path));
        } finally {
            $this->cleanup();
        }
    }

    private function createSgtinEpc(): Epc
    {
        $serial = (string) random_int(100000000, 999999999);
        $uri = 'urn:epc:id:sgtin:0399991.000001.'.$serial;
        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->epcIds[] = (int) $epc->getKey();

        return $epc;
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo 2',
                'profile' => TenantProfile::DrugWholesaler,
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
        if ($this->eventIds !== []) {
            foreach (['event_epcs_archive', 'event_epcs'] as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    DB::table($table)->whereIn('event_id', $this->eventIds)->delete();
                }
            }
            if (DB::getSchemaBuilder()->hasTable('epcis_events_archive')) {
                DB::table('epcis_events_archive')->whereIn('id', $this->eventIds)->delete();
            }
            EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
            $this->eventIds = [];
        }

        if ($this->documentIds !== []) {
            foreach (EpcisDocument::query()->whereIn('id', $this->documentIds)->get() as $doc) {
                if (filled($doc->payload_path)) {
                    try {
                        Storage::disk((string) ($doc->payload_disk ?: 'local'))->delete((string) $doc->payload_path);
                    } catch (\Throwable) {
                    }
                }
            }
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->epcIds !== []) {
            Epc::query()->whereIn('id', $this->epcIds)->delete();
            $this->epcIds = [];
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
