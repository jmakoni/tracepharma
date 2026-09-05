<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelBatchStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Site;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Labeling\SsccBuilder;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Dashboard\L3L4IngestLag;
use App\Support\Dashboard\LeadershipDscsaPackMetrics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class L3L4IngestLagTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $batchIds = [];

    /** @var list<int> */
    private array $labelIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function two_hour_lag_is_read_from_database_timestamps(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $sourceAt = now()->subHours(3);
            $l4At = now()->subHour();
            [$batch, , $event] = $this->seedCommissionedBatch($sourceAt, $l4At);

            $lag = app(L3L4IngestLag::class)->forBatch($batch->fresh());

            $this->assertNotNull($lag);
            $this->assertSame((int) $batch->getKey(), $lag['batch_id']);
            $this->assertGreaterThanOrEqual(1.9, $lag['lag_hours']);
            $this->assertLessThanOrEqual(2.1, $lag['lag_hours']);

            $event->forceFill(['event_time' => $l4At->copy()->addHour()])->save();

            $reread = app(L3L4IngestLag::class)->forBatch($batch->fresh());
            $this->assertNotNull($reread);
            $this->assertGreaterThanOrEqual(2.9, $reread['lag_hours']);
            $this->assertLessThanOrEqual(3.1, $reread['lag_hours']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function in_sla_batch_does_not_alert(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.l3_l4_ingest.sla_hours' => 4]);
            $user = $this->createOwner();
            $baseline = LeadershipDscsaPackMetrics::make($user, 'mtd')->l3L4IngestLag();

            [$batch] = $this->seedCommissionedBatch(now()->subMinutes(40), now()->subMinutes(10));

            $lag = app(L3L4IngestLag::class)->forBatch($batch->fresh());
            $this->assertNotNull($lag);
            $this->assertFalse($lag['over_sla']);

            $payload = LeadershipDscsaPackMetrics::make($user, 'mtd')->l3L4IngestLag();
            $row = collect($payload['rows'])->firstWhere('batch_id', (int) $batch->getKey());
            $this->assertIsArray($row);
            $this->assertFalse($row['over_sla']);
            $this->assertSame(
                (int) ($baseline['summary']['over_sla_count'] ?? 0),
                (int) $payload['summary']['over_sla_count'],
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function over_sla_batch_surfaces_on_the_leadership_pack(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.l3_l4_ingest.sla_hours' => 1]);
            $user = $this->createOwner();
            $baseline = LeadershipDscsaPackMetrics::make($user, 'mtd')->l3L4IngestLag();

            [$batch] = $this->seedCommissionedBatch(now()->subHours(3), now()->subHour());

            $lag = app(L3L4IngestLag::class)->forBatch($batch->fresh());
            $this->assertNotNull($lag);
            $this->assertTrue($lag['over_sla']);
            $this->assertGreaterThanOrEqual(1.9, $lag['lag_hours']);
            $this->assertLessThanOrEqual(2.1, $lag['lag_hours']);

            $payload = LeadershipDscsaPackMetrics::make($user, 'mtd')->l3L4IngestLag();
            $row = collect($payload['rows'])->firstWhere('batch_id', (int) $batch->getKey());
            $this->assertIsArray($row);
            $this->assertTrue($row['over_sla']);
            $this->assertSame(
                (int) ($baseline['summary']['over_sla_count'] ?? 0) + 1,
                (int) $payload['summary']['over_sla_count'],
            );
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{0: SsccLabelBatch, 1: Epc, 2: EpcisEvent}
     */
    private function seedCommissionedBatch(Carbon $sourceAt, Carbon $l4EventTime): array
    {
        $site = $this->createSite();
        $built = app(SsccBuilder::class)->build('0399991', random_int(100000, 999999), 0);

        $batch = SsccLabelBatch::query()->create([
            'company_prefix' => '0399991',
            'extension_digit' => '0',
            'allocation_mode' => SsccAllocationMode::Sequential,
            'label_count' => 1,
            'copies_per_label' => 1,
            'status' => SsccLabelBatchStatus::Completed,
            'commission_site_id' => $site->getKey(),
            'commissioned_at' => $sourceAt,
            'commissioning_epcis_file_path' => 'epcis/outbound/l3-l4-lag-'.Str::random(6).'.xml',
        ]);
        $this->batchIds[] = (int) $batch->getKey();

        $label = SsccLabel::query()->create([
            'batch_id' => $batch->getKey(),
            'sscc_18' => $built['sscc_18'],
            'sscc_urn' => $built['sscc_urn'],
            'extension_digit' => $built['extension_digit'],
            'company_prefix' => $built['company_prefix'],
            'serial_reference' => $built['serial_reference'],
            'serial_reference_int' => $built['serial_reference_int'],
            'element_string' => $built['element_string'],
            'hrt' => $built['hrt'],
            'label_disk' => 'local',
            'label_path' => 'labels/sscc/lag-'.$built['sscc_18'].'.pdf',
            'commissioned_at' => $sourceAt,
        ]);
        $this->labelIds[] = (int) $label->getKey();

        $epc = Epc::query()->firstOrCreate(
            ['epc_uri' => $built['sscc_urn']],
            Epc::materializeAttributesFromUri($built['sscc_urn']),
        );
        $this->epcIds[] = (int) $epc->getKey();

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => $l4EventTime,
            'received_at' => $l4EventTime,
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'lag-commission-'.Str::random(6).'.xml',
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => $l4EventTime,
            'record_time' => $l4EventTime,
            'event_timezone_offset' => '+00:00',
            'action' => 'ADD',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
            'disposition' => 'urn:epcglobal:cbv:disp:active',
        ]);
        $this->eventIds[] = (int) $event->getKey();

        DB::table('event_epcs')->insertOrIgnore([[
            'event_id' => $event->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]]);

        return [$batch->fresh(), $epc, $event->fresh()];
    }

    private function createSite(): Site
    {
        $site = Site::query()->create([
            'name' => 'L3L4 Lag Site '.Str::random(6),
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
            $check = 0;
            for ($i = 0; $i < 12; $i++) {
                $check += (int) $body[$i] * (($i % 2 === 0) ? 1 : 3);
            }
            $gln = $body.(string) ((10 - ($check % 10)) % 10);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
    }

    private function createOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
        $user = User::factory()->create([
            'email' => 'owner-l3-l4-lag-'.Str::uuid().'@example.com',
        ]);
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
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
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->eventIds !== []) {
            DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
            EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
            $this->eventIds = [];
        }

        if ($this->documentIds !== []) {
            $eventIds = EpcisEvent::query()
                ->whereIn('document_id', $this->documentIds)
                ->pluck('id')
                ->all();
            if ($eventIds !== []) {
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                EpcisEvent::query()->whereIn('id', $eventIds)->delete();
            }
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->labelIds !== []) {
            if (DB::getSchemaBuilder()->hasTable('sscc_label_children')) {
                DB::table('sscc_label_children')->whereIn('sscc_label_id', $this->labelIds)->delete();
            }
            SsccLabel::query()->whereIn('id', $this->labelIds)->delete();
            $this->labelIds = [];
        }

        if ($this->batchIds !== []) {
            SsccLabelBatch::query()->whereIn('id', $this->batchIds)->delete();
            $this->batchIds = [];
        }

        if ($this->epcIds !== []) {
            DB::table('event_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            Epc::query()->whereIn('id', $this->epcIds)->delete();
            $this->epcIds = [];
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        tenancy()->end();
    }
}
