<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\GenerateReceivingEpcisEvents;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\UnpackReceivingHierarchy;
use App\Enums\ReceivingSessionKind;
use App\Enums\TenantProfile;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Services\Tracing\BuildAssetTrace;
use App\Support\Gs1\Sgln;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

class GenerateReceivingEpcisEventsTest extends TestCase
{
    use PreparesDemo2ReceivingState;
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?int $sessionId = null;

    private ?int $receivingDocumentId = null;

    private ?int $unpackDocumentId = null;

    #[Test]
    public function completing_a_session_emits_a_receiving_object_event_and_is_idempotent(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->sessionId = (int) $session->getKey();

            app(ConfirmReceivingScan::class)->handle($session, self::SSCC_URI, userId: null, autoConfirmChildren: true);

            $session->refresh();
            if ($session->status !== 'completed') {
                $session = app(CompleteReceivingSession::class)->handle($session);
            }
            $this->assertSame('completed', $session->status);
            $this->assertNotNull($session->receiving_events_generated_at);
            $this->assertNotNull($session->receiving_epcis_document_id);
            $this->receivingDocumentId = (int) $session->receiving_epcis_document_id;

            $receivingDocument = EpcisDocument::query()->findOrFail($session->receiving_epcis_document_id);
            $this->assertSame('outbound', $receivingDocument->direction);
            $this->assertSame(1, $receivingDocument->event_count);
            $this->assertNotNull($receivingDocument->file_sha256);
            $this->assertTrue(Storage::disk($receivingDocument->payload_disk)->exists($receivingDocument->payload_path));

            $event = EpcisEvent::query()
                ->where('document_id', $receivingDocument->getKey())
                ->where('event_type', 'ObjectEvent')
                ->firstOrFail();

            $this->assertSame('OBSERVE', $event->action);
            $this->assertSame('urn:epcglobal:cbv:bizstep:receiving', $event->biz_step);
            $this->assertSame('urn:epcglobal:cbv:disp:in_progress', $event->disposition);
            $this->assertNotNull($event->event_id);
            $this->assertNotNull($event->record_time);
            $this->assertNotNull($event->event_timezone_offset);
            $this->assertFalse((bool) $receivingDocument->dscsa_affirm);
            $this->assertStringContainsString('Generated receiving', (string) $receivingDocument->notes);
            $this->assertSame('Generated receiving', $receivingDocument->directionDisplayLabel());

            $payload = Storage::disk($receivingDocument->payload_disk)->get($receivingDocument->payload_path);
            $this->assertIsString($payload);
            $this->assertStringContainsString('<disposition>urn:epcglobal:cbv:disp:in_progress</disposition>', $payload);
            $this->assertStringContainsString('<eventTimeZoneOffset>', $payload);
            $this->assertStringContainsString('<recordTime>', $payload);
            $this->assertStringContainsString('<eventID>', $payload);

            $confirmedEpcIds = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('status', 'confirmed')
                ->pluck('epc_id')
                ->all();

            $this->assertCount(2, $confirmedEpcIds);
            $this->assertSame(
                2,
                DB::table('event_epcs')
                    ->where('event_id', $event->getKey())
                    ->whereIn('epc_id', $confirmedEpcIds)
                    ->count(),
            );

            $session->loadMissing('site');
            $siteGln = Sgln::normalizeGln($session->site?->gln);
            $this->assertNotNull($session->site_id);
            $this->assertNotNull($siteGln);

            $locations = DB::table('event_locations')
                ->where('event_id', $event->getKey())
                ->orderBy('location_type')
                ->get();
            $this->assertCount(2, $locations);
            $this->assertSame(['bizLocation', 'readPoint'], $locations->pluck('location_type')->all());
            foreach ($locations as $row) {
                $this->assertSame($siteGln, $row->gln);
                $this->assertSame((int) $session->site_id, (int) $row->site_id);
                $this->assertNotEmpty($row->gln_uri);
                $parsed = Sgln::fromUrn((string) $row->gln_uri);
                $this->assertSame($siteGln, $parsed['gln'] ?? null);
            }

            $again = app(GenerateReceivingEpcisEvents::class)->handle($session->fresh());
            $this->assertFalse($again['generated']);
            $this->assertSame($receivingDocument->getKey(), $again['document']?->getKey());
            $this->assertSame(
                1,
                EpcisDocument::query()->where('direction', 'outbound')->where(
                    'notes',
                    'like',
                    "%receiving session #{$session->getKey()}.",
                )->count(),
                'Second call must not create a duplicate authored receiving document.',
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function stale_generated_marker_without_document_regenerates_receiving_events(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->sessionId = (int) $session->getKey();

            app(ConfirmReceivingScan::class)->handle($session, self::SSCC_URI, userId: null, autoConfirmChildren: true);
            $session->refresh();
            if ($session->status !== 'completed') {
                $session = app(CompleteReceivingSession::class)->handle($session);
            }
            $this->assertNotNull($session->receiving_epcis_document_id);
            $this->receivingDocumentId = (int) $session->receiving_epcis_document_id;

            // Simulate the stuck demo2 state: marker set, document gone.
            EpcisDocument::query()->whereKey($this->receivingDocumentId)->delete();
            $session->forceFill([
                'receiving_epcis_document_id' => null,
                'receiving_events_generated_at' => now(),
            ])->save();

            $repaired = app(GenerateReceivingEpcisEvents::class)->handle($session->fresh());
            $this->assertTrue($repaired['generated']);
            $this->assertNotNull($repaired['document']);
            $this->receivingDocumentId = (int) $repaired['document']->getKey();

            $session->refresh();
            $this->assertNotNull($session->receiving_epcis_document_id);
            $this->assertNotNull($session->receiving_events_generated_at);

            $trace = app(BuildAssetTrace::class)->handle(self::SSCC_URI);
            $this->assertTrue($trace['found']);
            $this->assertNotNull($trace['last_seen_at'] ?? null);
            $this->assertStringNotContainsStringIgnoringCase('Xttrium', (string) ($trace['last_seen_at'] ?? ''));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function generate_is_a_no_op_for_a_session_that_is_not_completed(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->sessionId = (int) $session->getKey();

            $this->assertSame('open', $session->status);

            $result = app(GenerateReceivingEpcisEvents::class)->handle($session);
            $this->assertFalse($result['generated']);
            $this->assertNull($result['document']);

            $completed = app(CompleteReceivingSession::class)->handle($session);
            $this->assertSame('open', $completed->status);
            $this->assertNull($completed->receiving_events_generated_at);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function unpack_flag_emits_aggregation_delete_and_closes_links(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();

            $this->assertTrue(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $child->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
                'Fixture should establish an open aggregation link before unpacking.',
            );

            $session = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'trading_partner_id' => $document->trading_partner_id,
                'status' => 'completed',
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 1,
                'confirmed_child_count' => 1,
                'opened_at' => now(),
                'completed_at' => now(),
            ]);
            $this->sessionId = (int) $session->getKey();

            ReceivingScanLine::query()->insert([
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $parent->getKey(),
                    'parent_epc_id' => null,
                    'line_role' => 'parent',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $child->getKey(),
                    'parent_epc_id' => $parent->getKey(),
                    'line_role' => 'child',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $this->assertSame(TenantProfile::Pharmacy, tenant()->profile);

            $result = app(GenerateReceivingEpcisEvents::class)->handle($session, null, unpack: true);

            $this->assertTrue($result['generated']);
            $this->assertNotNull($result['unpackEvent']);
            $this->assertSame('AggregationEvent', $result['unpackEvent']->event_type);
            $this->assertSame('DELETE', $result['unpackEvent']->action);
            $this->assertSame('urn:epcglobal:cbv:bizstep:unpacking', $result['unpackEvent']->biz_step);
            $this->receivingDocumentId = (int) $result['document']->getKey();

            $payload = Storage::disk($result['document']->payload_disk)->get($result['document']->payload_path);
            $this->assertIsString($payload);
            $this->assertLessThan(
                strpos($payload, '<AggregationEvent>'),
                strpos($payload, '<bizStep>urn:epcglobal:cbv:bizstep:receiving</bizStep>'),
            );

            $this->assertFalse(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $child->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
                'Unpacking must close the open aggregation link.',
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function complete_with_unpack_true_closes_links_for_pharmacy(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();

            $session = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'trading_partner_id' => $document->trading_partner_id,
                'status' => 'completed',
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 1,
                'confirmed_child_count' => 1,
                'opened_at' => now(),
                'completed_at' => now(),
            ]);
            $this->sessionId = (int) $session->getKey();

            ReceivingScanLine::query()->insert([
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $parent->getKey(),
                    'parent_epc_id' => null,
                    'line_role' => 'parent',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $child->getKey(),
                    'parent_epc_id' => $parent->getKey(),
                    'line_role' => 'child',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $this->assertSame(TenantProfile::Pharmacy, tenant()->profile);

            $completed = app(CompleteReceivingSession::class)->handle($session, null, unpack: true);
            $this->assertNotNull($completed->receiving_events_generated_at);
            $this->receivingDocumentId = (int) $completed->receiving_epcis_document_id;

            $this->assertTrue(
                EpcisEvent::query()
                    ->where('document_id', $completed->receiving_epcis_document_id)
                    ->where('event_type', 'AggregationEvent')
                    ->where('action', 'DELETE')
                    ->where('biz_step', 'urn:epcglobal:cbv:bizstep:unpacking')
                    ->exists(),
            );

            $this->assertFalse(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $child->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function complete_with_unpack_false_leaves_links_open(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();

            $session = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'trading_partner_id' => $document->trading_partner_id,
                'status' => 'completed',
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 1,
                'confirmed_child_count' => 1,
                'opened_at' => now(),
                'completed_at' => now(),
            ]);
            $this->sessionId = (int) $session->getKey();

            ReceivingScanLine::query()->insert([
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $parent->getKey(),
                    'parent_epc_id' => null,
                    'line_role' => 'parent',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $child->getKey(),
                    'parent_epc_id' => $parent->getKey(),
                    'line_role' => 'child',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $completed = app(CompleteReceivingSession::class)->handle($session, null, unpack: false);
            $this->assertNotNull($completed->receiving_events_generated_at);
            $this->receivingDocumentId = (int) $completed->receiving_epcis_document_id;

            $this->assertFalse(
                EpcisEvent::query()
                    ->where('document_id', $completed->receiving_epcis_document_id)
                    ->where('event_type', 'AggregationEvent')
                    ->exists(),
            );

            $this->assertTrue(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $child->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
                'Sealed receive must leave aggregation links open.',
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function after_receive_unpack_closes_links_for_wholesaler_profile(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();
            tenancy()->end();
            tenancy()->initialize($tenant->fresh());

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();

            $session = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'trading_partner_id' => $document->trading_partner_id,
                'status' => 'completed',
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 1,
                'confirmed_child_count' => 1,
                'opened_at' => now(),
                'completed_at' => now(),
            ]);
            $this->sessionId = (int) $session->getKey();

            ReceivingScanLine::query()->insert([
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $parent->getKey(),
                    'parent_epc_id' => null,
                    'line_role' => 'parent',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $child->getKey(),
                    'parent_epc_id' => $parent->getKey(),
                    'line_role' => 'child',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $completed = app(CompleteReceivingSession::class)->handle($session, null, unpack: false);
            $this->assertNotNull($completed->receiving_events_generated_at);
            $this->receivingDocumentId = (int) $completed->receiving_epcis_document_id;

            $this->assertTrue(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $child->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
            );

            // The unpack is a separate later step, so its eventTime is the unpack moment.
            ReceivingSession::query()
                ->whereKey($session->getKey())
                ->update(['completed_at' => now()->subHours(2)]);

            $unpacked = app(UnpackReceivingHierarchy::class)->handle($session->fresh());
            $this->assertTrue($unpacked['generated']);
            $this->assertNotNull($unpacked['unpackEvent']);
            $this->assertSame('DELETE', $unpacked['unpackEvent']->action);
            $this->assertGreaterThan(0, $unpacked['closed_links']);
            $this->assertTrue(
                Carbon::parse($unpacked['unpackEvent']->event_time)->greaterThan(now()->subMinutes(10)),
                'After-receive unpack eventTime must be the unpack time, not session completion.',
            );

            if ($unpacked['document'] !== null) {
                $this->unpackDocumentId = (int) $unpacked['document']->getKey();
            }

            $this->assertFalse(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $child->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
            );

            $again = app(GenerateReceivingEpcisEvents::class)->handle($session->fresh(), null, unpack: true);
            $this->assertFalse($again['generated']);
        } finally {
            Tenant::query()->whereKey(self::DEMO2_TENANT_ID)->update([
                'profile' => TenantProfile::Pharmacy->value,
            ]);
            $this->cleanup();
        }
    }

    #[Test]
    public function partial_unpack_closes_only_selected_children(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $extraChildUri = 'urn:epc:id:sgtin:030116.0200116.'.(string) random_int(90000082000000, 99999982999999);
        $extraChildId = null;

        try {
            $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();
            tenancy()->end();
            tenancy()->initialize($tenant->fresh());

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();

            $existingLink = AggregationLink::query()
                ->where('parent_epc_id', $parent->getKey())
                ->where('child_epc_id', $child->getKey())
                ->whereNull('valid_to')
                ->firstOrFail();

            $extraChild = Epc::query()->firstOrCreate(
                ['epc_uri' => $extraChildUri],
                Epc::materializeAttributesFromUri($extraChildUri),
            );
            $extraChildId = (int) $extraChild->getKey();

            AggregationLink::query()->firstOrCreate(
                [
                    'parent_epc_id' => $parent->getKey(),
                    'child_epc_id' => $extraChild->getKey(),
                    'established_by_event_id' => $existingLink->established_by_event_id,
                ],
                [
                    'link_type' => 'aggregation',
                    'valid_from' => now(),
                    'valid_to' => null,
                ],
            );

            AggregationLink::query()
                ->where('parent_epc_id', $parent->getKey())
                ->where('child_epc_id', $extraChild->getKey())
                ->update(['valid_to' => null]);

            $session = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'trading_partner_id' => $document->trading_partner_id,
                'status' => 'completed',
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 2,
                'confirmed_child_count' => 2,
                'opened_at' => now(),
                'completed_at' => now(),
            ]);
            $this->sessionId = (int) $session->getKey();

            ReceivingScanLine::query()->insert([
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $parent->getKey(),
                    'parent_epc_id' => null,
                    'line_role' => 'parent',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $child->getKey(),
                    'parent_epc_id' => $parent->getKey(),
                    'line_role' => 'child',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $extraChild->getKey(),
                    'parent_epc_id' => $parent->getKey(),
                    'line_role' => 'child',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $completed = app(CompleteReceivingSession::class)->handle($session, null, unpack: false);
            $this->assertNotNull($completed->receiving_events_generated_at);
            $this->receivingDocumentId = (int) $completed->receiving_epcis_document_id;

            $this->assertTrue(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $child->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
            );
            $this->assertTrue(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $extraChild->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
            );

            $unpacked = app(UnpackReceivingHierarchy::class)->handle(
                $session->fresh(),
                null,
                [(int) $child->getKey()],
            );
            $this->assertTrue($unpacked['generated']);
            $this->assertSame(1, $unpacked['closed_links']);

            if ($unpacked['document'] !== null) {
                $this->unpackDocumentId = (int) $unpacked['document']->getKey();
            }

            $this->assertFalse(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $child->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
                'Selected child link should be closed.',
            );
            $this->assertTrue(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $extraChild->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
                'Unselected child link should remain open.',
            );

            $eventChildIds = DB::table('event_epcs')
                ->where('event_id', $unpacked['unpackEvent']->getKey())
                ->where('role', 'childEPC')
                ->pluck('epc_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $this->assertSame([(int) $child->getKey()], $eventChildIds);
        } finally {
            if (tenancy()->initialized && $extraChildId !== null) {
                AggregationLink::query()->where('child_epc_id', $extraChildId)->delete();
                DB::table('event_epcs')->where('epc_id', $extraChildId)->delete();
                if (Schema::hasTable('document_epcs')) {
                    DB::table('document_epcs')->where('epc_id', $extraChildId)->delete();
                }
                Epc::query()->whereKey($extraChildId)->delete();
            }

            Tenant::query()->whereKey(self::DEMO2_TENANT_ID)->update([
                'profile' => TenantProfile::Pharmacy->value,
            ]);
            $this->cleanup();
        }
    }

    #[Test]
    public function receiving_xml_uses_tenant_company_prefix_for_site_sgln(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        $priorPrefix = $tenant->company_prefix;
        $priorGln = $tenant->gln;

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            // Unique GLN under company prefix 036615 (avoid colliding with seeded sites).
            $siteGln = '0366159000885';
            Site::query()->where('gln', $siteGln)->where('name', 'Receiving SGLN Site')->delete();

            $site = Site::query()->create([
                'name' => 'Receiving SGLN Site',
                'gln' => $siteGln,
                'is_active' => true,
            ]);

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => $siteGln,
                'company_prefix' => '036615',
            ]);
            tenancy()->end();
            tenancy()->initialize($tenant->fresh());

            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();

            $session = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'trading_partner_id' => $document->trading_partner_id,
                'site_id' => $site->getKey(),
                'status' => 'completed',
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 1,
                'confirmed_child_count' => 1,
                'opened_at' => now(),
                'completed_at' => now(),
            ]);
            $this->sessionId = (int) $session->getKey();

            ReceivingScanLine::query()->insert([
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $parent->getKey(),
                    'parent_epc_id' => null,
                    'line_role' => 'parent',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $child->getKey(),
                    'parent_epc_id' => $parent->getKey(),
                    'line_role' => 'child',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $result = app(GenerateReceivingEpcisEvents::class)->handle($session->fresh());
            $this->assertTrue($result['generated']);
            $this->receivingDocumentId = (int) $result['document']->getKey();

            $expectedUrn = 'urn:epc:id:sgln:036615.900088.0';
            $this->assertSame($expectedUrn, Sgln::toUrn($siteGln, 6));

            $payload = Storage::disk($result['document']->payload_disk)->get($result['document']->payload_path);
            $this->assertIsString($payload);
            // Site has GLN but only the non-GS1 generated sgln column — resolver must still
            // build readPoint/bizLocation from GLN + tenant company prefix.
            $this->assertStringContainsString('<readPoint>', $payload);
            $this->assertStringContainsString('<bizLocation>', $payload);
            $this->assertStringContainsString('<id>'.$expectedUrn.'</id>', $payload);
            $this->assertSame($siteGln, $result['event']->biz_location_gln);
            $this->assertSame($siteGln, $result['event']->read_point_gln);
        } finally {
            if (tenancy()->initialized) {
                if ($this->sessionId !== null) {
                    ReceivingSession::query()->whereKey($this->sessionId)->delete();
                    $this->sessionId = null;
                }
                Site::query()->where('gln', '0366159000885')->where('name', 'Receiving SGLN Site')->delete();
                $restored = Tenant::query()->find(self::DEMO2_TENANT_ID);
                if ($restored !== null) {
                    $restored->forceFill([
                        'company_prefix' => $priorPrefix,
                        'gln' => $priorGln,
                    ])->save();
                }
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function completed_receive_with_null_site_authors_events_with_tenant_gln(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        $priorPrefix = $tenant->company_prefix;
        $priorGln = $tenant->gln;
        $tenantGln = '0366159000010';

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => $tenantGln,
                'company_prefix' => '036615',
            ]);
            tenancy()->end();
            tenancy()->initialize($tenant->fresh());

            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();

            $session = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'trading_partner_id' => $document->trading_partner_id,
                'site_id' => null,
                'status' => 'completed',
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 1,
                'confirmed_child_count' => 1,
                'opened_at' => now(),
                'completed_at' => now(),
            ]);
            $this->sessionId = (int) $session->getKey();

            ReceivingScanLine::query()->insert([
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $parent->getKey(),
                    'parent_epc_id' => null,
                    'line_role' => 'parent',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $child->getKey(),
                    'parent_epc_id' => $parent->getKey(),
                    'line_role' => 'child',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $result = app(GenerateReceivingEpcisEvents::class)->handle($session->fresh());
            $this->assertTrue($result['generated']);
            $this->receivingDocumentId = (int) $result['document']->getKey();

            $expectedGln = Sgln::normalizeGln($tenantGln);
            $this->assertSame($expectedGln, $result['event']->biz_location_gln);
            $this->assertSame($expectedGln, $result['event']->read_point_gln);

            $trace = app(BuildAssetTrace::class)->handle(self::SGTIN_URI);
            $this->assertTrue($trace['found']);
            $this->assertNotEmpty($trace['last_seen_at']);
        } finally {
            if (tenancy()->initialized) {
                $restored = Tenant::query()->find(self::DEMO2_TENANT_ID);
                if ($restored !== null) {
                    $restored->forceFill([
                        'company_prefix' => $priorPrefix,
                        'gln' => $priorGln,
                    ])->save();
                }
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function site_gln_without_company_prefix_throws_and_complete_reverts_status(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        $priorPrefix = $tenant->company_prefix;
        $priorGln = $tenant->gln;
        $siteGln = '0366159000778';

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            Site::query()->where('gln', $siteGln)->where('name', 'Receiving SGLN Fail Site')->delete();

            $site = Site::query()->create([
                'name' => 'Receiving SGLN Fail Site',
                'gln' => $siteGln,
                'is_active' => true,
            ]);

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => $siteGln,
                'company_prefix' => null,
            ]);
            tenancy()->end();
            tenancy()->initialize($tenant->fresh());

            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();

            $session = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'trading_partner_id' => $document->trading_partner_id,
                'site_id' => $site->getKey(),
                'session_kind' => ReceivingSessionKind::ScanFirst,
                'status' => 'in_progress',
                'expected_parent_count' => 0,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 0,
                'confirmed_child_count' => 1,
                'opened_at' => now(),
            ]);
            $this->sessionId = (int) $session->getKey();

            ReceivingScanLine::query()->insert([
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $parent->getKey(),
                    'parent_epc_id' => null,
                    'line_role' => 'parent',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $child->getKey(),
                    'parent_epc_id' => $parent->getKey(),
                    'line_role' => 'child',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            try {
                app(CompleteReceivingSession::class)->handle($session->fresh());
                $this->fail('Expected DomainException when company prefix is missing.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('company prefix', strtolower($e->getMessage()));
            }

            $session->refresh();
            $this->assertSame('in_progress', $session->status);
            $this->assertNull($session->completed_at);
            $this->assertNull($session->receiving_events_generated_at);
            $this->assertNull($session->receiving_epcis_document_id);
        } finally {
            if (tenancy()->initialized) {
                if ($this->sessionId !== null) {
                    ReceivingSession::query()->whereKey($this->sessionId)->delete();
                    $this->sessionId = null;
                }
                Site::query()->where('gln', $siteGln)->where('name', 'Receiving SGLN Fail Site')->delete();
                $restored = Tenant::query()->find(self::DEMO2_TENANT_ID);
                if ($restored !== null) {
                    $restored->forceFill([
                        'company_prefix' => $priorPrefix,
                        'gln' => $priorGln,
                    ])->save();
                }
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function site_without_gln_fails_even_when_tenant_gln_is_configured(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        $priorPrefix = $tenant->company_prefix;
        $priorGln = $tenant->gln;
        $tenantGln = '0366159000010';

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => $tenantGln,
                'company_prefix' => '036615',
            ]);
            tenancy()->end();
            tenancy()->initialize($tenant->fresh());

            $site = Site::query()->create([
                'name' => 'Receiving No GLN Site',
                'gln' => null,
                'is_active' => true,
            ]);

            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();

            $session = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'trading_partner_id' => $document->trading_partner_id,
                'site_id' => $site->getKey(),
                'status' => 'completed',
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
                'completed_at' => now(),
            ]);
            $this->sessionId = (int) $session->getKey();

            ReceivingScanLine::query()->insert([
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $parent->getKey(),
                    'parent_epc_id' => null,
                    'line_role' => 'parent',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('receive site has no GLN');

            app(GenerateReceivingEpcisEvents::class)->handle($session->fresh());
        } finally {
            if (tenancy()->initialized) {
                if ($this->sessionId !== null) {
                    ReceivingSession::query()->whereKey($this->sessionId)->delete();
                    $this->sessionId = null;
                }
                Site::query()->where('name', 'Receiving No GLN Site')->delete();
                $restored = Tenant::query()->find(self::DEMO2_TENANT_ID);
                if ($restored !== null) {
                    $restored->forceFill([
                        'company_prefix' => $priorPrefix,
                        'gln' => $priorGln,
                    ])->save();
                }
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function no_site_or_tenant_gln_throws_domain_exception(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        $priorPrefix = $tenant->company_prefix;
        $priorGln = $tenant->gln;

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => null,
                'company_prefix' => '036615',
            ]);
            tenancy()->end();
            tenancy()->initialize($tenant->fresh());

            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();

            $session = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'trading_partner_id' => $document->trading_partner_id,
                'site_id' => null,
                'status' => 'completed',
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
                'completed_at' => now(),
            ]);
            $this->sessionId = (int) $session->getKey();

            ReceivingScanLine::query()->insert([
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $parent->getKey(),
                    'parent_epc_id' => null,
                    'line_role' => 'parent',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('no receive-site or organization GLN');

            app(GenerateReceivingEpcisEvents::class)->handle($session->fresh());
        } finally {
            if (tenancy()->initialized) {
                $restored = Tenant::query()->find(self::DEMO2_TENANT_ID);
                if ($restored !== null) {
                    $restored->forceFill([
                        'company_prefix' => $priorPrefix,
                        'gln' => $priorGln,
                    ])->save();
                }
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function receiving_event_copies_inbound_po_and_desadv_biz_transactions(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestFixture('tests/Fixtures/epcis/minimal_with_shipping_refs.xml');
            $this->documentId = (int) $document->getKey();

            $session = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'trading_partner_id' => $document->trading_partner_id,
                'status' => 'completed',
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 1,
                'confirmed_child_count' => 1,
                'opened_at' => now(),
                'completed_at' => now()->subMinute(),
            ]);
            $this->sessionId = (int) $session->getKey();

            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();

            ReceivingScanLine::query()->insert([
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $parent->getKey(),
                    'parent_epc_id' => null,
                    'line_role' => 'parent',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $child->getKey(),
                    'parent_epc_id' => $parent->getKey(),
                    'line_role' => 'child',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $result = app(GenerateReceivingEpcisEvents::class)->handle($session);
            $this->assertTrue($result['generated']);
            $this->receivingDocumentId = (int) $result['document']->getKey();

            $types = DB::table('event_biz_transactions')
                ->where('event_id', $result['event']->getKey())
                ->pluck('type_uri')
                ->all();

            $this->assertContains('urn:epcglobal:cbv:btt:po', $types);
            $this->assertContains('urn:epcglobal:cbv:btt:desadv', $types);

            $payload = Storage::disk($result['document']->payload_disk)->get($result['document']->payload_path);
            $this->assertIsString($payload);
            $this->assertStringContainsString('urn:epcglobal:cbv:btt:po', $payload);
            $this->assertStringContainsString('urn:epcglobal:cbv:btt:desadv', $payload);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function dual_disk_payload_failure_does_not_mark_events_generated(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();

            $session = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'trading_partner_id' => $document->trading_partner_id,
                'status' => 'completed',
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 1,
                'confirmed_child_count' => 1,
                'opened_at' => now(),
                'completed_at' => now(),
            ]);
            $this->sessionId = (int) $session->getKey();

            ReceivingScanLine::query()->insert([
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $parent->getKey(),
                    'parent_epc_id' => null,
                    'line_role' => 'parent',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $child->getKey(),
                    'parent_epc_id' => $parent->getKey(),
                    'line_role' => 'child',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $blockedRoot = sys_get_temp_dir().'/tp-blocked-epcis-'.uniqid('', true);
            file_put_contents($blockedRoot, 'not-a-directory');

            config([
                'tracepharma.epcis.payload_disk' => 'preferred_failing_disk',
                'filesystems.disks.preferred_failing_disk' => [
                    'driver' => 'local',
                    'root' => $blockedRoot,
                ],
                'filesystems.disks.local' => [
                    'driver' => 'local',
                    'root' => $blockedRoot,
                ],
            ]);
            Storage::forgetDisk('preferred_failing_disk');
            Storage::forgetDisk('local');

            $outboundBefore = EpcisDocument::query()
                ->where('direction', 'outbound')
                ->where('notes', 'like', "%receiving session #{$session->getKey()}.")
                ->count();

            try {
                app(GenerateReceivingEpcisEvents::class)->handle($session->fresh());
                $this->fail('Expected RuntimeException when preferred and local disks both fail.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Unable to persist Receiving EPCIS payload', $e->getMessage());
            }

            $session->refresh();
            $this->assertNull($session->receiving_events_generated_at);
            $this->assertNull($session->receiving_epcis_document_id);
            $this->assertSame(
                $outboundBefore,
                EpcisDocument::query()
                    ->where('direction', 'outbound')
                    ->where('notes', 'like', "%receiving session #{$session->getKey()}.")
                    ->count(),
                'Authored receiving document must roll back when payload cannot be stored.',
            );
        } finally {
            if (isset($blockedRoot) && is_file($blockedRoot)) {
                @unlink($blockedRoot);
            }
            $this->cleanup();
        }
    }

    private function ingestMinimalFixture(): EpcisDocument
    {
        return $this->ingestFixture('tests/Fixtures/epcis/minimal_object_shipping.xml');
    }

    private function ingestFixture(string $relativePath): EpcisDocument
    {
        $fixture = base_path($relativePath);
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) str()->uuid();
        $xml = str_replace('11111111-2222-3333-4444-555555555555', $uuid, $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => basename($fixture),
            ]);
        } finally {
            @unlink($tmp);
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
            if ($tenant->profile !== TenantProfile::Pharmacy) {
                $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            }
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        // Shared demo2 fixture: unpack/authoring tests need an org GLN when the session has no site.
        if (blank(TenantSettings::forTenant($tenant)->gln())) {
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);
        }

        $this->prepareFixtureReceivingState();

        return $tenant;
    }

    /**
     * Remove leftover receiving sessions for fixture EPCs so prior ASN runs do not
     * pollute the next test against shared demo2 state.
     *
     * @param  list<string>  $epcUris
     */
    private function prepareFixtureReceivingState(array $epcUris = [self::SSCC_URI, self::SGTIN_URI]): void
    {
        $this->ensureDemo2OrgPrefixMatchesReceiveSites();

        $epcIds = Epc::query()
            ->whereIn('epc_uri', $epcUris)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($epcIds === []) {
            return;
        }

        foreach ($epcIds as $epcId) {
            QuarantineHold::query()->where('epc_id', $epcId)->delete();
        }

        $sessionIds = ReceivingScanLine::query()
            ->whereIn('epc_id', $epcIds)
            ->distinct()
            ->pluck('receiving_session_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($sessionIds as $sessionId) {
            $session = ReceivingSession::query()->find($sessionId);
            if ($session === null) {
                continue;
            }

            if ($session->receiving_epcis_document_id !== null) {
                $receivingDocument = EpcisDocument::query()->find($session->receiving_epcis_document_id);
                if ($receivingDocument !== null && filled($receivingDocument->payload_path)) {
                    Storage::disk($receivingDocument->payload_disk)->delete($receivingDocument->payload_path);
                }
                EpcisDocument::query()->whereKey($session->receiving_epcis_document_id)->delete();
            }

            ReceivingScanLine::query()->where('receiving_session_id', $sessionId)->delete();
            $session->delete();
        }
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->unpackDocumentId !== null) {
                $unpackDocument = EpcisDocument::query()->find($this->unpackDocumentId);
                if ($unpackDocument !== null && filled($unpackDocument->payload_path)) {
                    Storage::disk($unpackDocument->payload_disk)->delete($unpackDocument->payload_path);
                }
                EpcisDocument::query()->whereKey($this->unpackDocumentId)->delete();
                $this->unpackDocumentId = null;
            }

            if ($this->receivingDocumentId !== null) {
                $receivingDocument = EpcisDocument::query()->find($this->receivingDocumentId);
                if ($receivingDocument !== null && filled($receivingDocument->payload_path)) {
                    Storage::disk($receivingDocument->payload_disk)->delete($receivingDocument->payload_path);
                }
                EpcisDocument::query()->whereKey($this->receivingDocumentId)->delete();
                $this->receivingDocumentId = null;
            }

            if ($this->sessionId !== null) {
                ReceivingSession::query()->whereKey($this->sessionId)->delete();
                $this->sessionId = null;
            }

            if ($this->documentId !== null) {
                EpcisDocument::query()->whereKey($this->documentId)->delete();
                $this->documentId = null;
            }

            $this->prepareFixtureReceivingState();

            foreach ([self::SGTIN_URI, self::SSCC_URI] as $uri) {
                $epc = Epc::query()->where('epc_uri', $uri)->first();
                if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                    if (DB::getSchemaBuilder()->hasTable('epc_ilmd')) {
                        DB::table('epc_ilmd')->where('epc_id', $epc->id)->delete();
                    }
                    $epc->delete();
                }
            }

            tenancy()->end();
        }
    }
}
