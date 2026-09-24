<?php

namespace Tests\Feature\Exceptions;

use App\Actions\Epcis\ReevaluateEpcisDocumentFindings;
use App\Enums\ExceptionActivityKind;
use App\Enums\ExceptionActivityVisibility;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\EpcisDocuments\Pages\ViewEpcisDocument;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EpcisException;
use App\Models\Epcis\EpcisUnmatchedGln;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Product;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use Database\Seeders\ExceptionTypeSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReevaluateEpcisDocumentFindingsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const CASE_GTIN = '50301162001160';

    private const EACH_GTIN = '00301162001165';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<string> */
    private array $payloadPaths = [];

    #[Test]
    public function known_gtins_and_resolving_glns_clear_unknown_cases_but_mixed_levels_stays_open(): void
    {
        $this->initializeDemo2Tenant();
        ExceptionTypeSeeder::ensure('UNKNOWN_GTIN');
        ExceptionTypeSeeder::ensure('UNKNOWN_GLN');
        ExceptionTypeSeeder::ensure('MIXED_PACKAGING_LEVELS');

        try {
            $this->ensureProducts();
            $this->ensurePartnersResolve();

            $document = $this->makeDocumentWithPayload();
            $sgtin = $this->makeSgtin(self::CASE_GTIN, 'mix1');
            $each = $this->makeSgtin(self::EACH_GTIN, 'each1');
            $sscc = $this->makeSscc();
            $this->attachDocumentEpc($document, $sgtin);
            $this->attachDocumentEpc($document, $each);
            $this->attachDocumentEpc($document, $sscc);

            $mixedEvent = $this->makeObjectEvent($document, now()->subDay(), 'ADD', 'urn:epcglobal:cbv:bizstep:commissioning');
            $this->attachEventEpc($mixedEvent, $sgtin, 'epcList');
            $this->attachEventEpc($mixedEvent, $sscc, 'epcList');

            foreach (['0301160000009', '0301160000016', '0860308000603'] as $gln) {
                EpcisUnmatchedGln::query()->create([
                    'document_id' => $document->getKey(),
                    'gln' => $gln,
                    'context' => 'source',
                ]);
            }

            $gtinCase = $this->openCase($document, 'UNKNOWN_GTIN', 'GTIN not found in product master: '.self::CASE_GTIN.'; GTIN not found in product master: '.self::EACH_GTIN);
            $glnCase = $this->openCase($document, 'UNKNOWN_GLN', 'Unmatched GLN referenced in document: 0301160000009');
            $mixedCase = $this->openCase($document, 'MIXED_PACKAGING_LEVELS', 'ObjectEvent epcList mixes SGTIN and SSCC packaging levels.');
            $gtinSignal = $this->signalForCase($gtinCase);
            $glnSignal = $this->signalForCase($glnCase);
            $mixedSignal = $this->signalForCase($mixedCase);
            $owningSignal = EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'exception_type' => 'DESTINATION_OWNING_PARTY_MISMATCH',
                'severity' => 'warning',
                'description' => 'Sold-to / destination owning party GLN (0860308000603) is not one of this tenant\'s organization or facility GLNs.',
                'status' => 'open',
            ]);
            $openedAt = $mixedCase->created_at?->toDateTimeString();

            $session = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'status' => 'completed',
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 1,
                'confirmed_child_count' => 1,
                'opened_at' => now()->subHour(),
                'completed_at' => now(),
            ]);
            $this->sessionIds[] = (int) $session->getKey();

            $generation = (int) $document->ingest_generation;
            $eventCount = EpcisEvent::query()->where('document_id', $document->getKey())->count();

            $result = app(ReevaluateEpcisDocumentFindings::class)->handle($document);

            $this->assertSame(ExceptionStatus::Cleared, $gtinCase->fresh()?->status);
            $this->assertNotNull($gtinCase->fresh()?->sla_stopped_at);
            $this->assertFalse((bool) $gtinCase->fresh()?->condition_still_true);
            $this->assertSame('resolved', $gtinSignal->fresh()?->status);
            $this->assertNotNull($gtinSignal->fresh()?->resolved_at);

            $this->assertSame(ExceptionStatus::Cleared, $glnCase->fresh()?->status);
            $this->assertNotNull($glnCase->fresh()?->sla_stopped_at);
            $this->assertSame('resolved', $glnSignal->fresh()?->status);
            $this->assertNotNull($glnSignal->fresh()?->resolved_at);

            $freshMixed = $mixedCase->fresh();
            $this->assertSame(ExceptionStatus::New, $freshMixed?->status);
            $this->assertSame($openedAt, $freshMixed?->created_at?->toDateTimeString());
            $this->assertNull($freshMixed?->sla_stopped_at);
            $this->assertTrue((bool) $freshMixed?->condition_still_true);
            $this->assertSame('open', $mixedSignal->fresh()?->status);
            $this->assertNull($mixedSignal->fresh()?->resolved_at);

            $freshOwning = $owningSignal->fresh();
            $this->assertSame('open', $freshOwning?->status);
            $this->assertNull($freshOwning?->case_id);
            $this->assertNull($freshOwning?->resolved_at);

            $this->assertSame($generation, (int) $document->fresh()?->ingest_generation);
            $this->assertSame($eventCount, EpcisEvent::query()->where('document_id', $document->getKey())->count());
            $this->assertTrue(EpcisDocument::query()->whereKey($document->getKey())->exists());
            $this->assertTrue(ReceivingSession::query()->whereKey($session->getKey())->exists());
            $this->assertSame('completed', $session->fresh()?->status);
            $this->assertNotEmpty($result['emitted']);
            $this->assertContains('MIXED_PACKAGING_LEVELS', $result['emitted']);
            $this->assertNotContains('UNKNOWN_GTIN', $result['emitted']);
            $this->assertNotContains('UNKNOWN_GLN', $result['emitted']);
            $this->assertNotContains('DESTINATION_OWNING_PARTY_MISMATCH', $result['emitted']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function leftover_open_signal_on_already_cleared_unknown_gtin_resolves_without_reopening_the_case(): void
    {
        $this->initializeDemo2Tenant();
        ExceptionTypeSeeder::ensure('UNKNOWN_GTIN');
        ExceptionTypeSeeder::ensure('MIXED_PACKAGING_LEVELS');

        try {
            $this->ensureProducts();

            $document = $this->makeDocumentWithPayload();
            $sgtin = $this->makeSgtin(self::CASE_GTIN, 'left1');
            $sscc = $this->makeSscc();
            $this->attachDocumentEpc($document, $sgtin);
            $this->attachDocumentEpc($document, $sscc);

            $mixedEvent = $this->makeObjectEvent($document, now()->subDay(), 'ADD', 'urn:epcglobal:cbv:bizstep:commissioning');
            $this->attachEventEpc($mixedEvent, $sgtin, 'epcList');
            $this->attachEventEpc($mixedEvent, $sscc, 'epcList');

            $gtinCase = $this->openCase($document, 'UNKNOWN_GTIN', 'GTIN not found in product master: '.self::CASE_GTIN);
            $mixedCase = $this->openCase($document, 'MIXED_PACKAGING_LEVELS', 'ObjectEvent epcList mixes SGTIN and SSCC packaging levels.');
            $gtinSignal = $this->signalForCase($gtinCase);
            $mixedSignal = $this->signalForCase($mixedCase);

            $gtinCase->forceFill([
                'status' => ExceptionStatus::Cleared,
                'condition_still_true' => false,
                'sla_stopped_at' => now()->subMinute(),
            ])->save();
            $this->assertSame('open', $gtinSignal->fresh()?->status);

            $session = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'status' => 'completed',
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 1,
                'confirmed_child_count' => 1,
                'opened_at' => now()->subHour(),
                'completed_at' => now(),
            ]);
            $this->sessionIds[] = (int) $session->getKey();

            $result = app(ReevaluateEpcisDocumentFindings::class)->handle($document);

            $freshGtin = $gtinCase->fresh();
            $this->assertSame(ExceptionStatus::Cleared, $freshGtin?->status);
            $this->assertFalse((bool) $freshGtin?->condition_still_true);
            $this->assertSame('resolved', $gtinSignal->fresh()?->status);
            $this->assertNotNull($gtinSignal->fresh()?->resolved_at);

            $this->assertSame(ExceptionStatus::New, $mixedCase->fresh()?->status);
            $this->assertSame('open', $mixedSignal->fresh()?->status);
            $this->assertNull($mixedSignal->fresh()?->resolved_at);

            $this->assertTrue(EpcisDocument::query()->whereKey($document->getKey())->exists());
            $this->assertTrue(ReceivingSession::query()->whereKey($session->getKey())->exists());
            $this->assertSame('completed', $session->fresh()?->status);
            $this->assertContains('MIXED_PACKAGING_LEVELS', $result['emitted']);
            $this->assertNotContains('UNKNOWN_GTIN', $result['emitted']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function failed_recompute_does_not_resolve_leftover_signals(): void
    {
        $this->initializeDemo2Tenant();
        ExceptionTypeSeeder::ensure('UNKNOWN_GTIN');

        try {
            $document = $this->makeDocumentWithPayload();
            $gtinCase = $this->openCase($document, 'UNKNOWN_GTIN', 'GTIN not found in product master: '.self::CASE_GTIN);
            $gtinSignal = $this->signalForCase($gtinCase);

            $gtinCase->forceFill([
                'status' => ExceptionStatus::Cleared,
                'condition_still_true' => false,
                'sla_stopped_at' => now()->subMinute(),
            ])->save();
            $this->assertSame('open', $gtinSignal->fresh()?->status);

            Storage::disk('local')->delete((string) $document->payload_path);

            app(ReevaluateEpcisDocumentFindings::class)->handle($document);

            $this->assertSame(ExceptionStatus::Cleared, $gtinCase->fresh()?->status);
            $this->assertSame('open', $gtinSignal->fresh()?->status);
            $this->assertNull($gtinSignal->fresh()?->resolved_at);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function time_order_fixture_keeps_events_out_of_order_open(): void
    {
        $this->initializeDemo2Tenant();
        ExceptionTypeSeeder::ensure('EVENTS_OUT_OF_ORDER');

        try {
            $document = $this->makeDocumentWithPayload();
            $epcId = $this->makeSgtin(self::CASE_GTIN, 'ooo1');
            $this->attachDocumentEpc($document, $epcId);

            $at = now()->subDays(2)->startOfSecond();
            $commission = $this->makeObjectEvent($document, $at, 'ADD', 'urn:epcglobal:cbv:bizstep:commissioning');
            $ship = $this->makeObjectEvent($document, $at, 'OBSERVE', 'urn:epcglobal:cbv:bizstep:shipping');
            $this->attachEventEpc($commission, $epcId, 'epcList');
            $this->attachEventEpc($ship, $epcId, 'epcList');

            $case = $this->openCase(
                $document,
                'EVENTS_OUT_OF_ORDER',
                'Multiple distinct events for the same EPC report identical event times, so their relative order cannot be determined.',
            );
            $openedAt = $case->created_at?->toDateTimeString();

            app(ReevaluateEpcisDocumentFindings::class)->handle($document);

            $fresh = $case->fresh();
            $this->assertSame(ExceptionStatus::New, $fresh?->status);
            $this->assertSame($openedAt, $fresh?->created_at?->toDateTimeString());
            $this->assertNull($fresh?->sla_stopped_at);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function failed_recompute_leaves_open_without_claiming_unemitted_types(): void
    {
        $this->initializeDemo2Tenant();
        ExceptionTypeSeeder::ensure('UNKNOWN_GLN');
        ExceptionTypeSeeder::ensure('INGESTION_PARSE_ERROR');
        ExceptionTypeSeeder::ensure('MIXED_PACKAGING_LEVELS');
        ExceptionTypeSeeder::ensure('EVENTS_OUT_OF_ORDER');

        try {
            $document = $this->makeDocumentWithPayload();
            $glnCase = $this->openCase($document, 'UNKNOWN_GLN', 'Unmatched GLN referenced in document: 0301160000009');
            $parseCase = $this->openCase($document, 'INGESTION_PARSE_ERROR', 'Stored payload could not be parsed.');
            $mixedCase = $this->openCase($document, 'MIXED_PACKAGING_LEVELS', 'ObjectEvent epcList mixes SGTIN and SSCC packaging levels.');
            $orderCase = $this->openCase(
                $document,
                'EVENTS_OUT_OF_ORDER',
                'Multiple distinct events for the same EPC report identical event times.',
            );

            Storage::disk('local')->delete((string) $document->payload_path);

            $result = app(ReevaluateEpcisDocumentFindings::class)->handle($document);

            $this->assertSame([], $result['cleared']);
            $this->assertSame(ExceptionStatus::New, $glnCase->fresh()?->status);
            $this->assertSame(ExceptionStatus::New, $parseCase->fresh()?->status);
            $this->assertSame(ExceptionStatus::New, $mixedCase->fresh()?->status);
            $this->assertSame(ExceptionStatus::New, $orderCase->fresh()?->status);
            $this->assertContains((int) $glnCase->getKey(), $result['left_open']);
            $this->assertContains((int) $parseCase->getKey(), $result['left_open']);
            $this->assertContains((int) $mixedCase->getKey(), $result['left_open']);
            $this->assertContains((int) $orderCase->getKey(), $result['left_open']);

            $glnNotes = $glnCase->activities()->pluck('body')->implode("\n");
            $this->assertStringNotContainsString('UNKNOWN_GLN still emitted', $glnNotes);
            $this->assertStringContainsString('validation failed; case left open.', $glnNotes);

            $parseNotes = $parseCase->activities()->pluck('body')->implode("\n");
            $this->assertStringNotContainsString('INGESTION_PARSE_ERROR still emitted', $parseNotes);
            $this->assertStringContainsString('validation failed; case left open.', $parseNotes);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function received_floor_status_shows_reevaluate_and_does_not_delete_the_document(): void
    {
        $this->initializeDemo2Tenant();
        ExceptionTypeSeeder::ensure('UNKNOWN_GTIN');

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create([
                'email' => 'reeval-floor-'.uniqid('', true).'@example.test',
            ]);
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $this->ensureProducts();
            $document = $this->makeDocumentWithPayload();
            $epcId = $this->makeSgtin(self::CASE_GTIN, 'recv1');
            $this->attachDocumentEpc($document, $epcId);
            $this->openCase($document, 'UNKNOWN_GTIN', 'GTIN not found in product master: '.self::CASE_GTIN);

            $session = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'status' => 'completed',
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 1,
                'confirmed_child_count' => 1,
                'opened_at' => now()->subHour(),
                'completed_at' => now(),
            ]);
            $this->sessionIds[] = (int) $session->getKey();

            $document->unsetRelation('receivingSession');
            $document->load('receivingSession');
            $this->assertTrue($document->isFloorReceived());

            Livewire::test(ViewEpcisDocument::class, ['record' => $document->getKey()])
                ->assertSuccessful()
                ->assertActionVisible('reevaluateFindings')
                ->assertActionHidden('reprocess');

            app(ReevaluateEpcisDocumentFindings::class)->handle($document);

            $this->assertTrue(EpcisDocument::query()->whereKey($document->getKey())->exists());
            $this->assertTrue(ReceivingSession::query()->whereKey($session->getKey())->exists());
            $this->assertSame('completed', $session->fresh()?->status);
        } finally {
            $this->cleanup();
        }
    }

    private function ensureProducts(): void
    {
        foreach ([self::CASE_GTIN, self::EACH_GTIN] as $gtin) {
            $product = Product::query()->firstOrCreate(
                ['gtin' => $gtin],
                ['name' => 'Reevaluate fixture '.$gtin, 'is_active' => true],
            );
            $this->productIds[] = (int) $product->getKey();
        }
    }

    private function ensurePartnersResolve(): void
    {
        foreach ([
            '0301160000009' => 'Xttrium reeval',
            '0301160000016' => 'Xttrium Glenview reeval',
            '0860308000603' => 'D&H reeval',
        ] as $gln => $name) {
            $partner = TradingPartner::query()->firstOrCreate(
                ['gln' => $gln],
                ['name' => $name, 'partner_type' => PartnerType::Manufacturer],
            );
            $this->partnerIds[] = (int) $partner->getKey();
        }
    }

    private function makeDocumentWithPayload(): EpcisDocument
    {
        $relative = 'epcis/inbound/reeval-'.Str::uuid().'.xml';
        Storage::disk('local')->put(
            $relative,
            (string) file_get_contents(base_path('tests/Fixtures/epcis/minimal_object_shipping.xml')),
        );
        $this->payloadPaths[] = $relative;

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => 'reeval.xml',
            'file_sha256' => hash('sha256', (string) Str::uuid()),
            'payload_disk' => 'local',
            'payload_path' => $relative,
            'dscsa_affirm' => true,
            'status' => 'validated',
            'event_count' => 1,
            'epc_count' => 1,
            'received_at' => now(),
            'received_via' => 'filament_upload',
            'ingest_generation' => 1,
        ]);
        $this->documentIds[] = (int) $document->getKey();

        return $document;
    }

    private function makeObjectEvent(
        EpcisDocument $document,
        mixed $time,
        string $action,
        string $bizStep,
    ): EpcisEvent {
        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'ingest_generation' => 1,
            'event_id' => (string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => $time,
            'record_time' => $time,
            'action' => $action,
            'biz_step' => $bizStep,
        ]);
        $this->eventIds[] = (int) $event->getKey();

        return $event;
    }

    private function makeSgtin(string $gtin14, string $serial): int
    {
        $id = (int) DB::table('epcs')->insertGetId([
            'epc_uri' => 'urn:epc:id:sgtin:030116.'.substr($gtin14, 1, 1).substr($gtin14, -6).'.'.$serial.random_int(1000, 9999),
            'epc_type' => 'sgtin',
            'company_prefix' => '030116',
            'gtin14' => $gtin14,
            'serial_number' => $serial,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->epcIds[] = $id;

        return $id;
    }

    private function makeSscc(): int
    {
        $sscc = '003011699999999991';
        $id = (int) DB::table('epcs')->insertGetId([
            'epc_uri' => 'urn:epc:id:sscc:030116.'.substr($sscc, -10).random_int(10, 99),
            'epc_type' => 'sscc',
            'company_prefix' => '030116',
            'sscc18' => $sscc,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->epcIds[] = $id;

        return $id;
    }

    private function attachDocumentEpc(EpcisDocument $document, int $epcId): void
    {
        DB::table('document_epcs')->insert([
            'document_id' => $document->getKey(),
            'epc_id' => $epcId,
            'ingest_generation' => 1,
        ]);
    }

    private function attachEventEpc(EpcisEvent $event, int $epcId, string $role): void
    {
        DB::table('event_epcs')->insert([
            'event_id' => $event->getKey(),
            'epc_id' => $epcId,
            'role' => $role,
        ]);
    }

    private function openCase(EpcisDocument $document, string $code, string $description): ExceptionCase
    {
        $type = ExceptionType::query()->where('code', $code)->first()
            ?? ExceptionTypeSeeder::ensure($code);

        $signal = EpcisException::query()->create([
            'document_id' => $document->getKey(),
            'exception_type' => $code,
            'severity' => 'error',
            'description' => $description,
            'status' => 'open',
        ]);

        $case = ExceptionCase::query()->create([
            'exception_type_id' => $type->getKey(),
            'document_id' => $document->getKey(),
            'title' => $type->name ?? $code,
            'description' => $description,
            'severity' => $type->default_severity ?? ExceptionSeverity::High,
            'status' => ExceptionStatus::New,
            'condition_still_true' => true,
        ]);
        $this->caseIds[] = (int) $case->getKey();

        $signal->forceFill(['case_id' => $case->getKey()])->save();
        $case->logActivity(
            ExceptionActivityKind::System,
            null,
            'Opened from ingest signal #'.$signal->getKey().' ('.$code.').',
            ExceptionActivityVisibility::Internal,
            ['epcis_exception_id' => $signal->getKey()],
        );

        return $case;
    }

    private function signalForCase(ExceptionCase $case): EpcisException
    {
        $signal = EpcisException::query()->where('case_id', $case->getKey())->first();
        $this->assertNotNull($signal);

        return $signal;
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

        if ($this->sessionIds !== []) {
            ReceivingSession::query()->whereIn('id', $this->sessionIds)->delete();
            $this->sessionIds = [];
        }

        if ($this->caseIds !== []) {
            DB::table('exception_activities')->whereIn('exception_id', $this->caseIds)->delete();
            DB::table('exception_epcs')->whereIn('exception_id', $this->caseIds)->delete();
            ExceptionCase::query()->whereIn('id', $this->caseIds)->delete();
            $this->caseIds = [];
        }

        if ($this->documentIds !== []) {
            EpcisUnmatchedGln::query()->whereIn('document_id', $this->documentIds)->delete();
            EpcisException::query()->whereIn('document_id', $this->documentIds)->delete();
            if ($this->eventIds !== []) {
                DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
                EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
                $this->eventIds = [];
            }
            DB::table('document_epcs')->whereIn('document_id', $this->documentIds)->delete();
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->epcIds !== []) {
            $linked = DB::table('event_epcs')->whereIn('epc_id', $this->epcIds)->pluck('epc_id')->all();
            $safe = array_values(array_diff($this->epcIds, $linked));
            if ($safe !== []) {
                DB::table('epcs')->whereIn('id', $safe)->delete();
            }
            $this->epcIds = [];
        }

        foreach ($this->payloadPaths as $path) {
            Storage::disk('local')->delete($path);
        }
        $this->payloadPaths = [];

        tenancy()->end();
    }
}
