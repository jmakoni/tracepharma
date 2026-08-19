<?php

namespace Tests\Feature\Exceptions;

use App\Enums\ExceptionDisposition;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EpcisException;
use App\Models\Exceptions\ExceptionAction;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionRootCause;
use App\Models\Exceptions\ExceptionType;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ExceptionUpdated;
use App\Services\Exceptions\ExceptionService;
use App\Services\Quarantine\QuarantineService;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use Database\Seeders\ExceptionCaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExceptionServiceTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $signalIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    #[Test]
    public function create_from_signal_denormalizes_ship_to_site_id_when_site_not_set(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = Site::query()->where('is_active', true)->first()
                ?? Site::query()->create([
                    'name' => 'Receive site',
                    'gln' => '0361230456789',
                    'is_active' => true,
                ]);
            $this->siteIds[] = (int) $site->getKey();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'creation_date' => now(),
                'received_at' => now(),
                'ship_to_site_id' => $site->getKey(),
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $signal = EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'exception_type' => 'ingest_failure',
                'severity' => 'error',
                'description' => 'Parse failed',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $signal->getKey();

            $case = app(ExceptionService::class)->createFromSignal($signal);
            $this->caseIds[] = (int) $case->getKey();

            $this->assertSame((int) $site->getKey(), (int) $case->site_id);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function create_from_signal_is_idempotent_and_maps_severity(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $signal = EpcisException::query()->create([
                'exception_type' => 'ingest_failure',
                'severity' => 'error',
                'description' => 'Parse failed',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $signal->getKey();

            $service = app(ExceptionService::class);
            $case = $service->createFromSignal($signal);
            $this->caseIds[] = (int) $case->getKey();

            $this->assertSame(ExceptionSeverity::High, $case->severity);
            $this->assertSame(ExceptionStatus::New, $case->status);
            $this->assertSame('INGESTION_PARSE_ERROR', $case->type->code);
            $this->assertNotNull($case->due_at);
            $this->assertSame($case->getKey(), $signal->fresh()->case_id);

            $again = $service->createFromSignal($signal->fresh());
            $this->assertSame($case->getKey(), $again->getKey());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function sbdh_source_owning_party_mismatch_maps_to_catalog_type_on_promote(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $signal = EpcisException::query()->create([
                'exception_type' => 'sbdh_source_owning_party_mismatch',
                'severity' => 'warning',
                'description' => 'SBDH Sender GLN (0301160000009) does not match shipping event source owning_party GLN (0361230456891).',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $signal->getKey();

            $case = app(ExceptionService::class)->createFromSignal($signal);
            $this->caseIds[] = (int) $case->getKey();

            $this->assertSame('SBDH_SOURCE_OWNING_PARTY_MISMATCH', $case->type->code);
            $this->assertSame(ExceptionSeverity::Medium, $case->severity);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function illegal_transition_is_rejected(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $service = app(ExceptionService::class);

            $signal = EpcisException::query()->create([
                'exception_type' => 'atp_soft_warning',
                'severity' => 'warning',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $signal->getKey();

            $case = $service->createFromSignal($signal, actor: $user);
            $this->caseIds[] = (int) $case->getKey();

            $this->expectException(ValidationException::class);
            $service->transition($case, ExceptionStatus::Closed, $user);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function resolve_requires_notes_and_sets_resolved_status(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $service = app(ExceptionService::class);

            $signal = EpcisException::query()->create([
                'exception_type' => 'missing_transaction_statement',
                'severity' => 'error',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $signal->getKey();

            $case = $service->createFromSignal($signal, actor: $user);
            $this->caseIds[] = (int) $case->getKey();

            $service->assign($case, $user, $user);
            $case->refresh();
            $service->transition($case, ExceptionStatus::Investigating, $user);
            $case->refresh();

            $rootCauseId = (int) ExceptionRootCause::query()->value('id');
            $actionId = (int) ExceptionAction::query()->value('id');

            $resolved = $service->resolve($case, $user, $rootCauseId, $actionId, 'Partner resent TI/TS.');

            $this->assertSame(ExceptionStatus::Resolved, $resolved->status);
            $this->assertNotNull($resolved->resolved_at);
            $this->assertSame('Partner resent TI/TS.', $resolved->resolution_notes);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function resolve_closes_the_originating_signal(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $service = app(ExceptionService::class);

            $signal = EpcisException::query()->create([
                'exception_type' => 'UNKNOWN_GTIN',
                'severity' => 'error',
                'description' => 'GTIN not found in product master: 30301164005087',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $signal->getKey();

            $case = $service->createFromSignal($signal, actor: $user);
            $this->caseIds[] = (int) $case->getKey();

            $service->assign($case, $user, $user);
            $case->refresh();
            $service->transition($case, ExceptionStatus::Investigating, $user);
            $case->refresh();

            $rootCauseId = (int) ExceptionRootCause::query()->value('id');
            $actionId = (int) ExceptionAction::query()->value('id');

            $service->resolve($case, $user, $rootCauseId, $actionId, 'Added GTIN to product master.');

            $this->assertSame('resolved', $signal->fresh()->status);
            $this->assertNotNull($signal->fresh()->resolved_at);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function resolve_always_assigns_the_case_to_the_resolver(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $resolver = User::factory()->create();
            $other = User::factory()->create();
            $service = app(ExceptionService::class);

            $signal = EpcisException::query()->create([
                'exception_type' => 'UNKNOWN_GTIN',
                'severity' => 'error',
                'description' => 'GTIN not found in product master: 30301164005087',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $signal->getKey();

            $case = $service->createFromSignal($signal, actor: $other);
            $this->caseIds[] = (int) $case->getKey();

            $this->assertNull($case->assigned_to);

            $rootCauseId = (int) ExceptionRootCause::query()->value('id');
            $actionId = (int) ExceptionAction::query()->value('id');

            $service->resolve($case, $resolver, $rootCauseId, $actionId, 'Resolved by logged-in user.');
            $case->refresh();

            $this->assertSame(ExceptionStatus::Resolved, $case->status);
            $this->assertSame((int) $resolver->getKey(), (int) $case->assigned_to);

            // Re-open path is out of scope; create a second case already assigned to someone else.
            $signal2 = EpcisException::query()->create([
                'exception_type' => 'UNKNOWN_GLN',
                'severity' => 'error',
                'description' => 'Unmatched GLN referenced in document: 1234567890123',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $signal2->getKey();

            $case2 = $service->createFromSignal($signal2, actor: $other);
            $this->caseIds[] = (int) $case2->getKey();
            $service->assign($case2, $other, $other);
            $case2->refresh();
            $this->assertSame((int) $other->getKey(), (int) $case2->assigned_to);

            $service->resolve($case2, $resolver, $rootCauseId, $actionId, 'Reassigned to resolver on resolve.');
            $case2->refresh();

            $this->assertSame(ExceptionStatus::Resolved, $case2->status);
            $this->assertSame((int) $resolver->getKey(), (int) $case2->assigned_to);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function resolve_does_not_send_an_assigned_notification(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $owner = User::factory()->create();
            $owner->syncRoles([TenantRole::Owner->value]);
            $resolver = User::factory()->create();
            $service = app(ExceptionService::class);

            $signal = EpcisException::query()->create([
                'exception_type' => 'UNKNOWN_GTIN',
                'severity' => 'error',
                'description' => 'GTIN not found in product master: 30301164005087',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $signal->getKey();

            $case = $service->createFromSignal($signal, actor: $resolver);
            $this->caseIds[] = (int) $case->getKey();

            $rootCauseId = (int) ExceptionRootCause::query()->value('id');
            $actionId = (int) ExceptionAction::query()->value('id');

            Notification::fake();

            $service->resolve($case->fresh(), $resolver, $rootCauseId, $actionId, 'Resolved without assigned mail.');

            Notification::assertSentTo(
                $owner,
                ExceptionUpdated::class,
                fn (ExceptionUpdated $notification): bool => $notification->action === 'resolved',
            );
            Notification::assertNotSentTo(
                $owner,
                ExceptionUpdated::class,
                fn (ExceptionUpdated $notification): bool => $notification->action === 'assigned',
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function create_from_signal_reuses_terminal_case_for_same_document_fingerprint(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $service = app(ExceptionService::class);
            $document = $this->createDocument();

            $signal = EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'exception_type' => 'UNKNOWN_GTIN',
                'severity' => 'error',
                'description' => 'GTIN not found in product master: 30301164005087',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $signal->getKey();

            $case = $service->createFromSignal($signal, actor: $user);
            $this->caseIds[] = (int) $case->getKey();

            $rootCauseId = (int) ExceptionRootCause::query()->value('id');
            $actionId = (int) ExceptionAction::query()->value('id');
            $service->resolve($case, $user, $rootCauseId, $actionId, 'Authorized GTIN.');
            $service->close($case->fresh(), $user);
            $case->refresh();
            $this->assertSame(ExceptionStatus::Closed, $case->status);

            // Recreated open signal after reprocess — same document + GTIN.
            $recreated = EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'exception_type' => 'UNKNOWN_GTIN',
                'severity' => 'error',
                'description' => 'GTIN not found in product master: 30301164005087',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $recreated->getKey();

            $returned = $service->createFromSignal($recreated, actor: $user);
            $this->assertSame($case->getKey(), $returned->getKey());
            $this->assertSame('resolved', $recreated->fresh()->status);
            $this->assertSame($case->getKey(), (int) $recreated->fresh()->case_id);

            // No duplicate open case for this fingerprint.
            $openDupes = ExceptionCase::query()
                ->where('document_id', $document->getKey())
                ->where('status', ExceptionStatus::New->value)
                ->where('description', 'like', '%30301164005087%')
                ->count();
            $this->assertSame(0, $openDupes);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function sync_signal_epcs_attaches_event_epc_list_for_mixed_packaging(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $service = app(ExceptionService::class);
            $document = $this->createDocument();

            $sgtinId = (int) DB::table('epcs')->insertGetId([
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.'.random_int(10000000000000, 99999999999999),
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'gtin14' => '00301162001165',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $ssccId = (int) DB::table('epcs')->insertGetId([
                'epc_uri' => 'urn:epc:id:sscc:030116.'.random_int(10000000000, 99999999999),
                'epc_type' => 'sscc',
                'company_prefix' => '030116',
                'sscc18' => '0030116'.random_int(1000000000, 9999999999),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->epcIds[] = $sgtinId;
            $this->epcIds[] = $ssccId;

            $eventId = (int) DB::table('epcis_events')->insertGetId([
                'document_id' => $document->getKey(),
                'ingest_generation' => 1,
                'event_id' => (string) str()->uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => now(),
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ([$sgtinId, $ssccId] as $epcId) {
                DB::table('event_epcs')->insert([
                    'event_id' => $eventId,
                    'epc_id' => $epcId,
                    'role' => 'epcList',
                ]);
            }

            $signal = EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'event_id' => $eventId,
                'exception_type' => 'MIXED_PACKAGING_LEVELS',
                'severity' => 'warning',
                'description' => 'ObjectEvent epcList mixes SGTIN and SSCC packaging levels.',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $signal->getKey();

            $case = $service->createFromSignal($signal, actor: $user);
            $this->caseIds[] = (int) $case->getKey();

            $this->assertSame(2, $case->epcs()->count());
            $this->assertSame(2, (int) $case->serials_affected);
            $this->assertEqualsCanonicalizing([$sgtinId, $ssccId], $case->epcs()->pluck('epcs.id')->map(fn ($id) => (int) $id)->all());
        } finally {
            if (isset($eventId)) {
                DB::table('event_epcs')->where('event_id', $eventId)->delete();
                DB::table('epcis_events')->where('id', $eventId)->delete();
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function sync_signal_epcs_does_not_inflate_serials_affected_past_open_holds(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $service = app(ExceptionService::class);
            $quarantine = app(QuarantineService::class);

            $epcA = Epc::query()->create([
                'epc_type' => 'sgtin',
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.sync-a',
                'gtin14' => '00301162001162',
                'serial_number' => 'sync-a',
                'company_prefix' => '030116',
                'first_seen_at' => now(),
            ]);
            $epcB = Epc::query()->create([
                'epc_type' => 'sgtin',
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.sync-b',
                'gtin14' => '00301162001162',
                'serial_number' => 'sync-b',
                'company_prefix' => '030116',
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epcA->getKey();
            $this->epcIds[] = (int) $epcB->getKey();

            $type = ExceptionType::query()->where('code', 'SUSPECT_PRODUCT')->firstOrFail();
            $case = $service->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Hold count sync test',
                'description' => 'One open hold, two pivot EPCs after sync',
                'severity' => ExceptionSeverity::Critical->value,
                'status' => ExceptionStatus::Investigating->value,
            ], [$epcA->getKey()], $user);
            $this->caseIds[] = (int) $case->getKey();

            $quarantine->openForCase($case, [$epcA->getKey()], 'Single-unit hold', $user);
            $case->refresh();
            $this->assertSame(1, (int) $case->serials_affected);

            $signal = EpcisException::query()->create([
                'document_id' => null,
                'epc_id' => $epcB->getKey(),
                'exception_type' => 'ingest_failure',
                'severity' => 'error',
                'description' => 'Attach second EPC',
                'status' => 'open',
                'case_id' => $case->getKey(),
            ]);
            $this->signalIds[] = (int) $signal->getKey();

            $service->syncSignalEpcs($case, $signal);
            $case->refresh();

            $this->assertSame(2, $case->epcs()->count());
            $this->assertSame(1, (int) $case->serials_affected);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function resolve_only_closes_unlinked_signals_with_matching_fingerprint(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $service = app(ExceptionService::class);

            $document = $this->createDocument();

            $signal = EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'exception_type' => 'UNKNOWN_GTIN',
                'severity' => 'error',
                'description' => 'GTIN not found in product master: 30301164005087',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $signal->getKey();

            $otherSignal = EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'exception_type' => 'UNKNOWN_GTIN',
                'severity' => 'error',
                'description' => 'GTIN not found in product master: 30301164005094',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $otherSignal->getKey();

            $case = $service->createFromSignal($signal, actor: $user);
            $this->caseIds[] = (int) $case->getKey();

            $service->assign($case, $user, $user);
            $case->refresh();
            $service->transition($case, ExceptionStatus::Investigating, $user);
            $case->refresh();

            $rootCauseId = (int) ExceptionRootCause::query()->value('id');
            $actionId = (int) ExceptionAction::query()->value('id');

            $service->resolve($case, $user, $rootCauseId, $actionId, 'Added GTIN A to product master.');

            $this->assertSame('resolved', $signal->fresh()->status);
            $this->assertSame('open', $otherSignal->fresh()->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function close_matching_signals_after_reprocess_closes_recreated_fingerprint(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $service = app(ExceptionService::class);
            $document = $this->createDocument();

            $signal = EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'exception_type' => 'UNKNOWN_GTIN',
                'severity' => 'error',
                'description' => 'GTIN not found in product master: 30301164005087',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $signal->getKey();

            $case = $service->createFromSignal($signal, actor: $user);
            $this->caseIds[] = (int) $case->getKey();

            $service->assign($case, $user, $user);
            $case->refresh();
            $service->transition($case, ExceptionStatus::Investigating, $user);
            $case->refresh();

            $rootCauseId = (int) ExceptionRootCause::query()->value('id');
            $actionId = (int) ExceptionAction::query()->value('id');

            $service->resolve($case, $user, $rootCauseId, $actionId, 'Authorized GTIN then reprocessed.');
            $this->assertSame('resolved', $signal->fresh()->status);

            // Simulate re-process recreating an open signal for the same GTIN.
            $recreated = EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'exception_type' => 'UNKNOWN_GTIN',
                'severity' => 'error',
                'description' => 'GTIN not found in product master: 30301164005087',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $recreated->getKey();

            $closed = $service->closeMatchingDocumentSignals($case->fresh());
            $this->assertGreaterThanOrEqual(1, $closed);
            $this->assertSame('resolved', $recreated->fresh()->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function resolve_closes_linked_signal_even_without_a_fingerprint(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $service = app(ExceptionService::class);

            $signal = EpcisException::query()->create([
                'exception_type' => 'atp_soft_warning',
                'severity' => 'warning',
                'description' => 'Placeholder master data detected.',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $signal->getKey();

            $case = $service->createFromSignal($signal, actor: $user);
            $this->caseIds[] = (int) $case->getKey();

            $service->assign($case, $user, $user);
            $case->refresh();
            $service->transition($case, ExceptionStatus::Investigating, $user);
            $case->refresh();

            $rootCauseId = (int) ExceptionRootCause::query()->value('id');
            $actionId = (int) ExceptionAction::query()->value('id');

            $service->resolve($case, $user, $rootCauseId, $actionId, 'Refreshed master data.');

            $this->assertSame('resolved', $signal->fresh()->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function close_matching_document_signals_without_fingerprint_matches_event_id(): void
    {
        $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant(tenant())->setJobRolesEnabled(false);
            tenant()->save();

            $user = User::query()->first() ?? User::factory()->create();
            $service = app(ExceptionService::class);
            $document = $this->createDocument();

            $event = EpcisEvent::query()->create([
                'document_id' => $document->getKey(),
                'event_id' => 'urn:uuid:'.(string) str()->uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => now(),
                'record_time' => now(),
                'event_timezone_offset' => '+00:00',
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
                'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
            ]);
            $eventId = (int) $event->getKey();

            $linkedSignal = EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'event_id' => $eventId,
                'exception_type' => 'MIXED_PACKAGING_LEVELS',
                'severity' => 'warning',
                'description' => 'Mixed packaging levels on event.',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $linkedSignal->getKey();

            $unlinkedSignal = EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'event_id' => $eventId,
                'exception_type' => 'MIXED_PACKAGING_LEVELS',
                'severity' => 'warning',
                'description' => 'Mixed packaging levels on event.',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $unlinkedSignal->getKey();

            $otherEvent = EpcisEvent::query()->create([
                'document_id' => $document->getKey(),
                'event_id' => 'urn:uuid:'.(string) str()->uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => now(),
                'record_time' => now(),
                'event_timezone_offset' => '+00:00',
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
                'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
            ]);

            $otherEventSignal = EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'event_id' => (int) $otherEvent->getKey(),
                'exception_type' => 'MIXED_PACKAGING_LEVELS',
                'severity' => 'warning',
                'description' => 'Different event scope.',
                'status' => 'open',
            ]);
            $this->signalIds[] = (int) $otherEventSignal->getKey();

            $case = $service->createFromSignal($linkedSignal, actor: $user);
            $this->caseIds[] = (int) $case->getKey();
            $case->forceFill(['event_id' => $eventId])->save();

            $service->assign($case, $user, $user);
            $case->refresh();
            $service->transition($case, ExceptionStatus::Investigating, $user);
            $case->refresh();

            $rootCauseId = (int) ExceptionRootCause::query()->value('id');
            $actionId = (int) ExceptionAction::query()->value('id');

            $service->resolve($case, $user, $rootCauseId, $actionId, 'Hierarchy corrected on event.');

            $this->assertSame('resolved', $linkedSignal->fresh()->status);
            $this->assertSame('resolved', $unlinkedSignal->fresh()->status);
            $this->assertSame('open', $otherEventSignal->fresh()->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function resolve_is_blocked_while_open_quarantine_holds_remain(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $epc = $this->createEpc();
            $case = app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [$epc->id],
                reason: 'Hold before resolve',
                actor: $user,
            );
            $this->caseIds[] = (int) $case->getKey();

            $rootCauseId = (int) ExceptionRootCause::query()->value('id');
            $actionId = (int) ExceptionAction::query()->value('id');

            try {
                app(ExceptionService::class)->resolve(
                    $case,
                    $user,
                    $rootCauseId,
                    $actionId,
                    'Should not resolve with open holds.',
                );
                $this->fail('Expected ValidationException');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('open quarantine holds', collect($e->errors())->flatten()->first() ?? '');
            }

            $this->assertSame(ExceptionStatus::Investigating, $case->fresh()->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function resolve_allowed_after_clear_or_when_marked_illegitimate(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $service = app(ExceptionService::class);
            $quarantine = app(QuarantineService::class);
            $rootCauseId = (int) ExceptionRootCause::query()->value('id');
            $actionId = (int) ExceptionAction::query()->value('id');

            $clearedEpc = $this->createEpc();
            $clearedCase = $quarantine->quarantineFromFindRecall(
                epcIds: [$clearedEpc->id],
                reason: 'Clear then resolve',
                actor: $user,
            );
            $this->caseIds[] = (int) $clearedCase->getKey();
            $quarantine->clearForDistribution($clearedCase, $user, 'Verified authentic.');
            $resolved = $service->resolve(
                $clearedCase->fresh(),
                $user,
                $rootCauseId,
                $actionId,
                'Cleared and closed out.',
            );
            $this->assertSame(ExceptionStatus::Resolved, $resolved->status);

            $illegitEpc = $this->createEpc();
            $illegitCase = $quarantine->quarantineFromFindRecall(
                epcIds: [$illegitEpc->id],
                reason: 'Illegitimate then resolve',
                actor: $user,
            );
            $this->caseIds[] = (int) $illegitCase->getKey();
            $quarantine->markIllegitimate($illegitCase, $user, 'Counterfeit confirmed.');
            $this->assertSame(1, QuarantineHold::query()->open()->where('exception_id', $illegitCase->getKey())->count());

            $resolvedIllegit = $service->resolve(
                $illegitCase->fresh(),
                $user,
                $rootCauseId,
                $actionId,
                'Illegitimate determination recorded; holds remain.',
            );
            $this->assertSame(ExceptionStatus::Resolved, $resolvedIllegit->status);
            $this->assertSame(ExceptionDisposition::Illegitimate, $resolvedIllegit->disposition);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function quarantine_product_resolution_opens_holds_and_stays_investigating(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $service = app(ExceptionService::class);
            $epc = $this->createEpc();
            $type = ExceptionType::query()->where('code', 'SUSPECT_PRODUCT')->firstOrFail();

            $case = $service->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Suspect product',
                'description' => 'Integrity signal',
                'severity' => ExceptionSeverity::Critical->value,
                'status' => ExceptionStatus::New->value,
            ], [$epc->id], $user);
            $this->caseIds[] = (int) $case->getKey();

            $quarantineActionId = (int) ExceptionAction::query()
                ->where('code', 'quarantine_product')
                ->value('id');
            $rootCauseId = (int) ExceptionRootCause::query()->value('id');

            $resolved = $service->resolve(
                $case,
                $user,
                $rootCauseId,
                $quarantineActionId,
                'Hold affected units pending lab review.',
            );

            $this->assertSame(ExceptionStatus::Investigating, $resolved->status);
            $this->assertSame(1, QuarantineHold::query()->open()->where('exception_id', $case->getKey())->count());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function close_records_compliance_reason_in_activity_and_resolution_notes_when_blank(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first() ?? User::factory()->create();
            $service = app(ExceptionService::class);
            $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Close reason audit',
                'description' => 'GTIN not found in product master: 30301164005087',
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::Resolved,
                'resolved_at' => now(),
            ]);
            $this->caseIds[] = (int) $case->getKey();

            $closed = $service->close($case, $user, 'Compliance close: investigation complete.');

            $this->assertSame(ExceptionStatus::Closed, $closed->status);
            $this->assertSame('Compliance close: investigation complete.', $closed->resolution_notes);
            $this->assertTrue(
                $case->activities()
                    ->where('body', 'Compliance close: investigation complete.')
                    ->exists(),
            );
        } finally {
            $this->cleanup();
        }
    }

    private function createEpc(): Epc
    {
        $suffix = substr((string) str()->uuid(), 0, 8);
        $epc = Epc::query()->create([
            'epc_type' => 'sgtin',
            'epc_uri' => "urn:epc:id:sgtin:030116.0200116.x{$suffix}",
            'gtin14' => '00301162001162',
            'serial_number' => "x{$suffix}",
            'company_prefix' => '030116',
            'first_seen_at' => now(),
        ]);
        $this->epcIds[] = (int) $epc->id;

        return $epc;
    }

    private function createDocument(): EpcisDocument
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'direction' => 'inbound',
            'creation_date' => now(),
            'received_at' => now(),
        ]);
        $this->documentIds[] = (int) $document->getKey();

        return $document;
    }

    #[Test]
    public function create_strips_unlisted_attributes_from_mass_assignment(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $type = ExceptionType::query()->where('code', 'SUSPECT_PRODUCT')->firstOrFail();

            $case = app(ExceptionService::class)->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Allowlist test',
                'description' => 'Safe attrs only',
                'severity' => ExceptionSeverity::High->value,
                'status' => ExceptionStatus::New->value,
                'share_uuid' => '00000000-0000-0000-0000-000000000099',
                'resolved_at' => now(),
            ]);

            $this->caseIds[] = (int) $case->getKey();

            $this->assertNull($case->share_uuid);
            $this->assertNull($case->resolved_at);
        } finally {
            $this->cleanup();
        }
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

        $this->seed(ExceptionCaseSeeder::class);

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->signalIds as $id) {
            EpcisException::query()->whereKey($id)->update(['case_id' => null]);
            EpcisException::query()->whereKey($id)->delete();
        }

        foreach ($this->caseIds as $id) {
            $case = ExceptionCase::query()->find($id);
            if ($case === null) {
                continue;
            }
            $case->activities()->delete();
            QuarantineHold::query()->where('exception_id', $id)->delete();
            $case->epcs()->detach();
            $case->delete();
        }

        foreach ($this->epcIds as $id) {
            QuarantineHold::query()->where('epc_id', $id)->delete();
            Epc::query()->whereKey($id)->delete();
        }

        foreach ($this->documentIds as $id) {
            EpcisDocument::query()->whereKey($id)->delete();
        }

        $this->signalIds = [];
        $this->caseIds = [];
        $this->epcIds = [];
        $this->documentIds = [];
        tenancy()->end();
    }
}
