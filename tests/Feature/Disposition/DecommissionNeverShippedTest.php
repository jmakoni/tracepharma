<?php

namespace Tests\Feature\Disposition;

use App\Actions\Disposition\DecommissionNeverShippedEpcs;
use App\Actions\Disposition\EmitCommissioningEpcisForEpcs;
use App\Enums\EpcisAuthoredKind;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Site;
use App\Models\Tenant;
use App\Support\Exceptions\ExceptionCorrectionProfile;
use App\Support\Gs1\Gtin;
use App\Support\TenantSettings;
use Database\Seeders\ExceptionTypeSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DecommissionNeverShippedTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $exceptionCaseIds = [];

    /** @var list<int> */
    private array $holdIds = [];

    private ?TenantProfile $priorProfile = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    private bool $capturedOrganization = false;

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    #[Test]
    public function aged_unshipped_commissioned_epc_is_decommissioned(): void
    {
        Storage::fake('local');
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->prepareTenant($tenant);
            [$site, $epc] = $this->commissionOnHandUnit();
            $this->backdateCommissioning($epc, now()->subDays(2));

            $result = app(DecommissionNeverShippedEpcs::class)->handle((int) $site->getKey());

            $this->assertSame(1, $result['decommissioned']);
            $this->assertSame(0, $result['failed']);
            $this->assertSame(1, $this->decommissioningEventCount($epc));

            $event = $this->latestDecommissioningEvent($epc);
            $this->assertNotNull($event);
            $this->documentIds[] = (int) $event->document_id;
            $this->assertStringContainsString('disposed', (string) $event->disposition);
            $this->assertSame(
                'qa_reject_never_shipped',
                data_get($event->extension_json, 'decommission_reason'),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function recent_commission_is_skipped(): void
    {
        Storage::fake('local');
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->prepareTenant($tenant);
            [$site, $epc] = $this->commissionOnHandUnit();

            $result = app(DecommissionNeverShippedEpcs::class)->handle((int) $site->getKey());

            $this->assertSame(0, $result['decommissioned']);
            $this->assertSame(0, $this->decommissioningEventCount($epc));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function shipped_epc_is_skipped(): void
    {
        Storage::fake('local');
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->prepareTenant($tenant);
            [$site, $epc] = $this->commissionOnHandUnit();
            $this->backdateCommissioning($epc, now()->subDays(2));
            $this->seedShippingEvent($site, $epc);

            $result = app(DecommissionNeverShippedEpcs::class)->handle((int) $site->getKey());

            $this->assertSame(0, $result['decommissioned']);
            $this->assertSame(0, $this->decommissioningEventCount($epc));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function forced_failure_opens_auto_decommission_failed(): void
    {
        Storage::fake('local');
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->prepareTenant($tenant);
            $this->ensureExceptionTypes();
            [$site, $epc] = $this->commissionOnHandUnit();
            $this->backdateCommissioning($epc, now()->subDays(2));

            $this->assertFalse(ExceptionCorrectionProfile::isOperatorHiddenStubCode('AUTO_DECOMMISSION_FAILED'));

            $hold = QuarantineHold::query()->create([
                'epc_id' => $epc->getKey(),
                'reason' => 'Forced auto-decommission failure',
                'status' => 'open',
                'severity' => 'error',
                'opened_at' => now(),
            ]);
            $this->holdIds[] = (int) $hold->getKey();

            $result = app(DecommissionNeverShippedEpcs::class)->handle((int) $site->getKey());

            $this->assertSame(0, $result['decommissioned']);
            $this->assertSame(1, $result['failed']);
            $this->assertSame(0, $this->decommissioningEventCount($epc));

            $type = ExceptionType::query()->where('code', 'AUTO_DECOMMISSION_FAILED')->first();
            $this->assertNotNull($type);

            $case = ExceptionCase::query()
                ->where('exception_type_id', $type->getKey())
                ->whereNotIn('status', [
                    ExceptionStatus::Resolved->value,
                    ExceptionStatus::Closed->value,
                    ExceptionStatus::Cancelled->value,
                ])
                ->whereHas('epcs', fn ($query) => $query->where('epcs.id', $epc->getKey()))
                ->first();

            $this->assertNotNull($case);
            $this->exceptionCaseIds[] = (int) $case->getKey();
            $this->assertStringContainsString('quarantine', strtolower((string) $case->description));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function second_run_does_not_write_duplicate_decommission_events(): void
    {
        Storage::fake('local');
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->prepareTenant($tenant);
            [$site, $epc] = $this->commissionOnHandUnit();
            $this->backdateCommissioning($epc, now()->subDays(2));

            app(DecommissionNeverShippedEpcs::class)->handle((int) $site->getKey());
            $first = $this->latestDecommissioningEvent($epc);
            $this->assertNotNull($first);
            $this->documentIds[] = (int) $first->document_id;
            $this->assertSame(1, $this->decommissioningEventCount($epc));

            $second = app(DecommissionNeverShippedEpcs::class)->handle((int) $site->getKey());

            $this->assertSame(0, $second['decommissioned']);
            $this->assertSame(1, $this->decommissioningEventCount($epc));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function never_shipped_auto_decommission_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('disposition:decommission-never-shipped')
            ->assertSuccessful();

        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => $event->description === 'decommission-never-shipped');

        $this->assertNotNull($event, 'decommission-never-shipped is not scheduled.');
        $this->assertSame('30 2 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function prepareTenant(Tenant $tenant): void
    {
        $this->setProfile($tenant, TenantProfile::DrugWholesaler);
        $this->configureOrganization($tenant);
        config(['tracepharma.decommission.unshipped_hold_days' => 1]);
    }

    /**
     * @return array{0: Site, 1: Epc}
     */
    private function commissionOnHandUnit(): array
    {
        $site = $this->createSite(tenant());
        $epc = $this->createEpc();
        $this->receiveAtSite($site, $epc);

        $result = app(EmitCommissioningEpcisForEpcs::class)->handle(
            [(int) $epc->getKey()],
            (int) $site->getKey(),
            ['sync' => true, 'dispatch' => true],
        );

        $this->assertSame(1, $result['commissioned_count']);
        $this->assertNotNull($result['document']);
        $this->documentIds[] = (int) $result['document']->getKey();

        return [$site, $epc->fresh() ?? $epc];
    }

    private function backdateCommissioning(Epc $epc, \DateTimeInterface $eventTime): void
    {
        $eventIds = DB::table('event_epcs')
            ->where('epc_id', $epc->getKey())
            ->pluck('event_id')
            ->all();

        EpcisEvent::query()
            ->whereIn('id', $eventIds)
            ->where('event_type', 'ObjectEvent')
            ->where(function ($query): void {
                $query->where('biz_step', 'urn:epcglobal:cbv:bizstep:commissioning')
                    ->orWhere('biz_step', 'commissioning')
                    ->orWhere('biz_step', 'like', '%:commissioning');
            })
            ->update([
                'event_time' => $eventTime,
                'record_time' => $eventTime,
            ]);
    }

    private function seedShippingEvent(Site $site, Epc $epc): void
    {
        $this->seedEvent(
            $site,
            $epc,
            action: 'OBSERVE',
            bizStep: 'urn:epcglobal:cbv:bizstep:shipping',
            disposition: 'urn:epcglobal:cbv:disp:in_transit',
            eventTime: now(),
        );
    }

    private function decommissioningEventCount(Epc $epc): int
    {
        return (int) DB::table('event_epcs')
            ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
            ->join('epcis_documents', 'epcis_documents.id', '=', 'epcis_events.document_id')
            ->where('event_epcs.epc_id', $epc->getKey())
            ->where('epcis_events.event_type', 'ObjectEvent')
            ->where('epcis_events.biz_step', 'like', '%decommissioning%')
            ->where('epcis_documents.authored_kind', EpcisAuthoredKind::Decommissioning->value)
            ->count();
    }

    private function latestDecommissioningEvent(Epc $epc): ?EpcisEvent
    {
        $eventId = DB::table('event_epcs')
            ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
            ->join('epcis_documents', 'epcis_documents.id', '=', 'epcis_events.document_id')
            ->where('event_epcs.epc_id', $epc->getKey())
            ->where('epcis_events.event_type', 'ObjectEvent')
            ->where('epcis_events.biz_step', 'like', '%decommissioning%')
            ->where('epcis_documents.authored_kind', EpcisAuthoredKind::Decommissioning->value)
            ->orderByDesc('epcis_events.id')
            ->value('epcis_events.id');

        if ($eventId === null) {
            return null;
        }

        return EpcisEvent::query()->find((int) $eventId);
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
            'name' => 'Never Shipped Site '.Str::random(6),
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

    private function receiveAtSite(Site $site, Epc $epc): void
    {
        $this->seedEvent(
            $site,
            $epc,
            action: 'OBSERVE',
            bizStep: 'urn:epcglobal:cbv:bizstep:receiving',
            disposition: 'urn:epcglobal:cbv:disp:in_progress',
            eventTime: now()->subMinute(),
        );
    }

    private function seedEvent(
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
            'original_filename' => 'never-shipped-seed-'.Str::random(6).'.xml',
            'notes' => 'Seeded event for never-shipped auto-decommission test.',
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

    private function ensureExceptionTypes(): void
    {
        if (ExceptionType::query()->where('code', 'AUTO_DECOMMISSION_FAILED')->exists()) {
            return;
        }

        (new ExceptionTypeSeeder)->run();
    }

    private function cleanup(Tenant $tenant): void
    {
        if ($this->holdIds !== []) {
            QuarantineHold::query()->whereIn('id', $this->holdIds)->delete();
            $this->holdIds = [];
        }

        if ($this->exceptionCaseIds !== []) {
            DB::table('exception_epcs')->whereIn('exception_id', $this->exceptionCaseIds)->delete();
            ExceptionCase::query()->whereIn('id', $this->exceptionCaseIds)->delete();
            $this->exceptionCaseIds = [];
        }

        if ($this->eventIds !== []) {
            DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
            EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
            $this->eventIds = [];
        }

        if ($this->documentIds !== []) {
            $casesOnDocs = ExceptionCase::query()
                ->whereIn('document_id', $this->documentIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            if ($casesOnDocs !== []) {
                DB::table('exception_epcs')->whereIn('exception_id', $casesOnDocs)->delete();
                ExceptionCase::query()->whereIn('id', $casesOnDocs)->delete();
            }

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
            $orphanCases = ExceptionCase::query()
                ->whereHas('epcs', fn ($query) => $query->whereIn('epcs.id', $this->epcIds))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            if ($orphanCases !== []) {
                DB::table('exception_epcs')->whereIn('exception_id', $orphanCases)->delete();
                ExceptionCase::query()->whereIn('id', $orphanCases)->delete();
            }

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
        $this->capturedOrganization = false;
        $this->priorGln = null;
        $this->priorCompanyPrefix = null;
        $this->priorProfile = null;

        tenancy()->end();
    }
}
