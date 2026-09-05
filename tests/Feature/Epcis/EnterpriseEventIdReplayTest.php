<?php

declare(strict_types=1);

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\ArchiveAgedEpcisEvents;
use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Tenant;
use App\Services\Epcis\EpcisIngestionService;
use App\Support\Epcis\LiveAcceptedEpcisEventId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnterpriseEventIdReplayTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<string> */
    private array $tmpPaths = [];

    #[Test]
    public function ingest_replay_of_accepted_event_id_does_not_create_second_live_row(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $eventId = 'urn:uuid:'.(string) Str::uuid();

            $accepted = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'status' => 'validated',
                'original_filename' => 'accepted-'.Str::random(6).'.xml',
                'received_at' => now(),
                'ingest_generation' => 1,
            ]);
            $this->documentIds[] = (int) $accepted->getKey();

            EpcisEvent::query()->create([
                'document_id' => $accepted->getKey(),
                'ingest_generation' => 1,
                'event_id' => $eventId,
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subHour(),
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                'disposition' => 'urn:epcglobal:cbv:disp:active',
            ]);

            $tmp = $this->writeInboundXmlWithEventId($eventId);
            $replay = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'replay-'.Str::random(6).'.xml',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $replay->getKey();

            app(EpcisIngestionService::class)->process($replay);

            $live = EpcisEvent::query()
                ->where('event_id', $eventId)
                ->when(
                    Schema::hasColumn('epcis_events', 'superseded_at'),
                    fn ($query) => $query->whereNull('superseded_at'),
                )
                ->get();

            $this->assertCount(1, $live);
            $this->assertSame((int) $accepted->getKey(), (int) $live->first()?->document_id);
            $this->assertSame(
                0,
                EpcisEvent::query()
                    ->where('document_id', $replay->getKey())
                    ->where('event_id', $eventId)
                    ->count(),
            );
            $this->assertSame(0, (int) $replay->fresh()->event_count);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function ingest_replay_still_skips_after_accepted_event_is_archived(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.epcis.retention_years' => 1]);
            $eventId = 'urn:uuid:'.(string) Str::uuid();

            $accepted = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'status' => 'validated',
                'original_filename' => 'accepted-arch-'.Str::random(6).'.xml',
                'received_at' => now(),
                'ingest_generation' => 1,
            ]);
            $this->documentIds[] = (int) $accepted->getKey();

            $hot = EpcisEvent::query()->create([
                'document_id' => $accepted->getKey(),
                'ingest_generation' => 1,
                'event_id' => $eventId,
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subYears(2),
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                'disposition' => 'urn:epcglobal:cbv:disp:active',
            ]);

            app(ArchiveAgedEpcisEvents::class)->handle();

            $this->assertDatabaseMissing('epcis_events', ['id' => $hot->getKey()]);
            $this->assertDatabaseHas('epcis_events_archive', [
                'id' => $hot->getKey(),
                'event_id' => $eventId,
            ]);
            $this->assertTrue(
                app(LiveAcceptedEpcisEventId::class)
                    ->existsOnOtherDocument($eventId, (int) $accepted->getKey() + 1),
            );

            $tmp = $this->writeInboundXmlWithEventId($eventId);
            $replay = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'replay-arch-'.Str::random(6).'.xml',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $replay->getKey();

            app(EpcisIngestionService::class)->process($replay);

            $this->assertSame(
                0,
                EpcisEvent::query()
                    ->where('document_id', $replay->getKey())
                    ->where('event_id', $eventId)
                    ->count(),
            );
            $this->assertSame(0, (int) $replay->fresh()->event_count);
        } finally {
            if (tenancy()->initialized && isset($hot)) {
                DB::table('event_epcs_archive')->where('event_id', $hot->getKey())->delete();
                DB::table('epcis_events_archive')->where('id', $hot->getKey())->delete();
            }
            $this->cleanup();
        }
    }

    private function writeInboundXmlWithEventId(string $eventId): string
    {
        $serial = (string) random_int(100000000, 999999999);
        $path = sys_get_temp_dir().'/epcis-event-replay-'.(string) Str::uuid().'.xml';
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument
    xmlns:epcis="urn:epcglobal:epcis:xsd:1"
    schemaVersion="1.2"
    creationDate="2026-07-15T20:15:49.056Z">
  <EPCISBody>
    <EventList>
      <ObjectEvent>
        <eventTime>2026-06-18T23:27:32.897Z</eventTime>
        <eventTimeZoneOffset>-05:00</eventTimeZoneOffset>
        <baseExtension>
          <eventID>{$eventId}</eventID>
        </baseExtension>
        <epcList>
          <epc>urn:epc:id:sgtin:030116.0200116.{$serial}</epc>
        </epcList>
        <action>ADD</action>
        <bizStep>urn:epcglobal:cbv:bizstep:commissioning</bizStep>
        <disposition>urn:epcglobal:cbv:disp:active</disposition>
      </ObjectEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;
        file_put_contents($path, $xml);
        $this->tmpPaths[] = $path;

        return $path;
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
        if (tenancy()->initialized && $this->documentIds !== []) {
            DB::table('event_epcs')->whereIn('event_id', function ($query): void {
                $query->select('id')->from('epcis_events')->whereIn('document_id', $this->documentIds);
            })->delete();
            EpcisEvent::query()->whereIn('document_id', $this->documentIds)->delete();
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        foreach ($this->tmpPaths as $path) {
            @unlink($path);
        }
        $this->tmpPaths = [];

        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
