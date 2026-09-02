<?php

namespace App\Actions\Receiving;

use App\Actions\Epcis\SyncDocumentEpcsFromEvents;
use App\Actions\Outbound\AssertAuthoredAggregationCandidate;
use App\Domain\Epcis\Enums\EpcisAction;
use App\Enums\EpcisAuthoredKind;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Rules\ValidGln;
use App\Services\Custody\EpcCustodyGate;
use App\Services\Receiving\ReceivingGate;
use App\Support\Auth\CurrentSite;
use App\Support\Epcis\PersistEpcisXmlPayload;
use App\Support\Epcis\ScheduleOutboundEpcisTransmission;
use App\Support\Gs1\EpcBarcodeDisplay;
use App\Support\Gs1\Sgln;
use App\Support\Gs1\SglnResolution;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * After-receive hierarchy break: author AggregationEvent DELETE(s) and close open
 * aggregation_links for confirmed parents — without re-emitting the receiving ObjectEvent.
 *
 * Use when receiving EPCIS was already generated with unpack=false (typical for
 * wholesaler/3PL profiles that unpack as a separate step).
 */
final class UnpackReceivingHierarchy
{
    private const DISPOSITION_IN_PROGRESS = 'urn:epcglobal:cbv:disp:in_progress';

    private const BIZ_STEP_UNPACKING = 'urn:epcglobal:cbv:bizstep:unpacking';

    public function __construct(
        private readonly SyncDocumentEpcsFromEvents $syncDocumentEpcsFromEvents,
        private readonly ScheduleOutboundEpcisTransmission $scheduleOutboundTransmission,
        private readonly PersistEpcisXmlPayload $persistEpcisXmlPayload,
        private readonly EpcCustodyGate $custodyGate,
        private readonly AssertAuthoredAggregationCandidate $assertCandidate,
    ) {}

    /**
     * @param  list<int|string>|null  $childEpcKeys  Optional child EPC ids and/or URNs to close.
     *                                               Null or empty = unpack all open children (default).
     * @param  bool  $eventTimeFromSessionCompletion  Only for an unpack that physically happened at
     *                                                receive time; the default (after-receive unpack)
     *                                                is a later step, so eventTime is now().
     * @return array{
     *     document: ?EpcisDocument,
     *     unpackEvent: ?EpcisEvent,
     *     generated: bool,
     *     closed_links: int
     * }
     */
    public function handle(
        ReceivingSession $session,
        ?int $actorId = null,
        ?array $childEpcKeys = null,
        bool $eventTimeFromSessionCompletion = false,
    ): array {
        $session = $session->fresh() ?? $session;

        if ($session->status !== 'completed') {
            throw new DomainException('Unpack hierarchy requires a completed receiving session.');
        }

        if ($session->receiving_events_generated_at === null) {
            throw new DomainException('Unpack hierarchy requires receiving EPCIS events to have been generated for this session.');
        }

        $policy = ReceivingPolicy::forTenant(tenant());
        if (! $policy->canUnpackAfterReceive()) {
            throw new DomainException('This tenant profile cannot unpack after receive.');
        }

        $session->loadMissing('site', 'tradingPartner', 'document');

        $childFilter = $this->resolveChildEpcIdFilter($childEpcKeys);

        $openParentIds = $this->confirmedParentEpcIdsWithOpenLinks($session, $childFilter);
        if ($openParentIds === []) {
            return [
                'document' => null,
                'unpackEvent' => null,
                'generated' => false,
                'closed_links' => 0,
            ];
        }

        $built = DB::transaction(function () use ($session, $childFilter, $eventTimeFromSessionCompletion): array {
            $recordTime = now();
            $eventTime = ($eventTimeFromSessionCompletion && $session->completed_at !== null)
                ? Carbon::parse($session->completed_at)
                : $recordTime;
            $timezoneOffset = $this->timezoneOffset($session, $eventTime);
            $location = $this->resolveReceivingLocationGlns($session);
            $gln = $location['gln'];
            $sglnUrn = $location['sgln_urn'];

            $document = $this->createUnpackDocument($session, $recordTime);

            $unpacked = $this->authorEventsOnDocument(
                $session,
                $document,
                $eventTime,
                $recordTime,
                $timezoneOffset,
                $gln,
                $childFilter,
            );

            if ($unpacked['blocks'] === []) {
                $document->delete();

                return [
                    'document' => null,
                    'unpackEvent' => null,
                    'generated' => false,
                    'closed_links' => 0,
                ];
            }

            $xml = $this->buildUnpackXml(
                eventTime: $eventTime,
                recordTime: $recordTime,
                timezoneOffset: $timezoneOffset,
                sglnUrn: $sglnUrn,
                unpackBlocks: $unpacked['blocks'],
            );

            $epcCount = $this->syncDocumentEpcsFromEvents->handle($document);

            $document->forceFill([
                'event_count' => count($unpacked['blocks']),
                'epc_count' => $epcCount,
                'status' => 'parsed',
                'processed_at' => $recordTime,
                'last_processed_at' => $recordTime,
            ])->save();

            $this->persistEpcisXmlPayload->handle(
                $document,
                $xml,
                (string) $document->payload_path,
                (string) $document->payload_disk,
                'Receiving unpack EPCIS',
            );

            return [
                'document' => $document->refresh(),
                'unpackEvent' => $unpacked['first_event'],
                'generated' => true,
                'closed_links' => $unpacked['closed_links'],
            ];
        });

        if (($built['generated'] ?? false) && $built['document'] !== null) {
            // Internal hierarchy breaks have no trading partner — keep them off outbound.
            if (filled($built['document']->trading_partner_id)) {
                $this->scheduleOutboundTransmission->afterPersist($built['document'], true);
            }
        }

        return $built;
    }

    /**
     * Parent-first partial unpack (workstation): close open aggregation_links for one
     * parent + selected children and author AggregationEvent DELETE(s).
     *
     * @param  list<int|string>  $childEpcKeys  Required non-empty child EPC ids and/or URNs.
     * @return array{
     *     document: ?EpcisDocument,
     *     unpackEvent: ?EpcisEvent,
     *     generated: bool,
     *     closed_links: int
     * }
     */
    public function handleParent(Epc $parent, array $childEpcKeys, ?Site $site = null, ?int $actorId = null): array
    {
        if ($childEpcKeys === []) {
            throw new DomainException('Partial unpack requires at least one child EPC.');
        }

        $policy = ReceivingPolicy::forTenant(tenant());
        $features = TenantFeatures::forTenant(tenant());
        if (! $policy->canUnpackAtReceive() && ! $features->supportsUnpacking()) {
            throw new DomainException('This tenant profile cannot unpack hierarchy.');
        }

        $childFilter = $this->resolveChildEpcIdFilter($childEpcKeys);
        if ($childFilter === null || $childFilter === []) {
            throw new DomainException('No valid child EPCs resolved for unpack.');
        }

        $site ??= $this->resolveWorkstationSite();

        $built = DB::transaction(function () use ($parent, $childFilter, $site, $actorId): array {
            $recordTime = now();
            $eventTime = $recordTime;
            $timezoneOffset = $this->timezoneOffsetForSite($site, $eventTime);
            $location = $this->resolveLocationGlnsForSite($site);
            $gln = $location['gln'];
            $sglnUrn = $location['sgln_urn'];

            $document = $this->createParentUnpackDocument($parent, $recordTime, $actorId);

            $unpacked = $this->authorEventsForParent(
                $parent,
                $document,
                $eventTime,
                $recordTime,
                $timezoneOffset,
                $gln,
                $childFilter,
            );

            if ($unpacked['blocks'] === []) {
                $document->delete();

                return [
                    'document' => null,
                    'unpackEvent' => null,
                    'generated' => false,
                    'closed_links' => 0,
                ];
            }

            $xml = $this->buildUnpackXml(
                eventTime: $eventTime,
                recordTime: $recordTime,
                timezoneOffset: $timezoneOffset,
                sglnUrn: $sglnUrn,
                unpackBlocks: $unpacked['blocks'],
            );

            $epcCount = $this->syncDocumentEpcsFromEvents->handle($document);

            $document->forceFill([
                'event_count' => count($unpacked['blocks']),
                'epc_count' => $epcCount,
                'status' => 'parsed',
                'processed_at' => $recordTime,
                'last_processed_at' => $recordTime,
            ])->save();

            $this->persistEpcisXmlPayload->handle(
                $document,
                $xml,
                (string) $document->payload_path,
                (string) $document->payload_disk,
                'Parent unpack EPCIS',
            );

            return [
                'document' => $document->refresh(),
                'unpackEvent' => $unpacked['first_event'],
                'generated' => true,
                'closed_links' => $unpacked['closed_links'],
            ];
        });

        if (($built['generated'] ?? false) && $built['document'] !== null) {
            // Internal hierarchy breaks have no trading partner — keep them off outbound.
            if (filled($built['document']->trading_partner_id)) {
                $this->scheduleOutboundTransmission->afterPersist($built['document'], true);
            }
        }

        return $built;
    }

    /**
     * Open children under a parent — for pack/unpack workstation UI.
     *
     * @return array<int, string> epc_id => label
     */
    public function openChildOptionsForParent(Epc $parent): array
    {
        $childIds = AggregationLink::query()
            ->where('parent_epc_id', $parent->getKey())
            ->whereNull('valid_to')
            ->orderBy('child_epc_id')
            ->pluck('child_epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->unheldChildOptions($childIds);
    }

    /**
     * Author AggregationEvent DELETE rows + close open links for confirmed parents.
     * Shared by GenerateReceivingEpcisEvents (inline unpack) and after-receive handle().
     *
     * @param  list<int>|null  $childEpcIds  When non-null/non-empty, only close these children.
     * @param  bool  $failOnQuarantine  Deliberate unpack (after receive) runs the full custody gate
     *                                  and aborts on an open hold; the inline receive path is part of
     *                                  taking custody, so it skips the gate and leaves held hierarchy
     *                                  sealed instead of failing the receipt.
     * @return array{
     *     first_event: ?EpcisEvent,
     *     blocks: list<array{event_id: string, parent_epc_id: int, child_epc_ids: list<int>}>,
     *     closed_links: int
     * }
     */
    public function authorEventsOnDocument(
        ReceivingSession $session,
        EpcisDocument $document,
        Carbon $eventTime,
        Carbon $recordTime,
        string $timezoneOffset,
        ?string $gln,
        ?array $childEpcIds = null,
        bool $failOnQuarantine = true,
    ): array {
        $parentEpcIds = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('line_role', 'parent')
            ->where('status', 'confirmed')
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($parentEpcIds === []) {
            return ['first_event' => null, 'blocks' => [], 'closed_links' => 0];
        }

        $childFilter = ($childEpcIds !== null && $childEpcIds !== [])
            ? array_values(array_unique(array_map('intval', $childEpcIds)))
            : null;

        $openLinksQuery = AggregationLink::query()
            ->whereIn('parent_epc_id', $parentEpcIds)
            ->whereNull('valid_to');

        if ($childFilter !== null) {
            $openLinksQuery->whereIn('child_epc_id', $childFilter);
        }

        $openLinks = $openLinksQuery
            ->lockForUpdate()
            ->get(['id', 'parent_epc_id', 'child_epc_id']);

        if ($openLinks->isEmpty()) {
            return ['first_event' => null, 'blocks' => [], 'closed_links' => 0];
        }

        $lockedParentIds = $openLinks->pluck('parent_epc_id')->map(fn ($id): int => (int) $id)->all();
        $lockedChildIds = $openLinks->pluck('child_epc_id')->map(fn ($id): int => (int) $id)->all();

        // Custody and holds are read after the row lock so a hold raised while we queued
        // cannot slip through between the check and the link close.
        if ($failOnQuarantine) {
            $this->custodyGate->assertOperableFor([...$lockedParentIds, ...$lockedChildIds], 'unpacking');
        } else {
            $heldEpcIds = $this->openHoldEpcIds([...$lockedParentIds, ...$lockedChildIds]);

            if ($heldEpcIds !== []) {
                $openLinks = $openLinks
                    ->reject(fn ($link): bool => in_array((int) $link->parent_epc_id, $heldEpcIds, true)
                        || in_array((int) $link->child_epc_id, $heldEpcIds, true))
                    ->values();

                if ($openLinks->isEmpty()) {
                    return ['first_event' => null, 'blocks' => [], 'closed_links' => 0];
                }
            }
        }

        $firstEvent = null;
        $blocks = [];
        $closedLinkIds = [];

        foreach ($openLinks->groupBy('parent_epc_id') as $parentEpcId => $links) {
            $parentId = (int) $parentEpcId;
            $childIds = $links->map(fn ($link): int => (int) $link->child_epc_id)->values()->all();

            $this->preflightUnpackAggregationCandidate($parentId, $childIds, $eventTime);

            $eventUuid = 'urn:uuid:'.(string) Str::uuid();

            $event = EpcisEvent::query()->create($this->authoredEventAttributes([
                'document_id' => $document->getKey(),
                'event_id' => $eventUuid,
                'event_type' => 'AggregationEvent',
                'event_time' => $eventTime,
                'record_time' => $recordTime,
                'event_timezone_offset' => $timezoneOffset,
                'action' => 'DELETE',
                'biz_step' => self::BIZ_STEP_UNPACKING,
                'disposition' => self::DISPOSITION_IN_PROGRESS,
                'read_point_gln' => $gln,
                'biz_location_gln' => $gln,
                'trading_partner_id' => $session->trading_partner_id,
            ]));

            $firstEvent ??= $event;

            $eventEpcRows = [[
                'event_id' => $event->getKey(),
                'epc_id' => $parentId,
                'role' => 'parentID',
            ]];
            foreach ($childIds as $childId) {
                $eventEpcRows[] = [
                    'event_id' => $event->getKey(),
                    'epc_id' => $childId,
                    'role' => 'childEPC',
                ];
            }
            DB::table('event_epcs')->insertOrIgnore($eventEpcRows);

            $blocks[] = [
                'event_id' => $eventUuid,
                'parent_epc_id' => $parentId,
                'child_epc_ids' => $childIds,
            ];
            foreach ($links as $link) {
                $closedLinkIds[] = (int) $link->id;
            }
        }

        if ($closedLinkIds !== []) {
            AggregationLink::query()
                ->whereIn('id', $closedLinkIds)
                ->update(['valid_to' => $recordTime]);
        }

        return [
            'first_event' => $firstEvent,
            'blocks' => $blocks,
            'closed_links' => count($closedLinkIds),
        ];
    }

    /**
     * Open children under confirmed parents — for after-receive partial unpack UI.
     *
     * @return array<int, string> epc_id => label
     */
    public function openChildOptionsForConfirmedParents(ReceivingSession $session): array
    {
        $parentEpcIds = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('line_role', 'parent')
            ->where('status', 'confirmed')
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($parentEpcIds === []) {
            return [];
        }

        $childIds = AggregationLink::query()
            ->whereIn('parent_epc_id', $parentEpcIds)
            ->whereNull('valid_to')
            ->orderBy('child_epc_id')
            ->pluck('child_epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->unheldChildOptions($childIds);
    }

    /**
     * Only children the unpack gate will accept are offered: quarantined ones need the
     * hold resolved first, and out-of-custody ones belong to a partner's hierarchy that
     * merely appears in our event store. Offering either would stage a selection that
     * {@see authorEventsForParent()} rejects at confirm time.
     *
     * @param  list<int>  $childIds
     * @return array<int, string>
     */
    private function unheldChildOptions(array $childIds): array
    {
        if ($childIds === []) {
            return [];
        }

        $heldEpcIds = $this->openHoldEpcIds($childIds);

        $offerableIds = $this->custodyGate->epcIdsInCustody(
            array_values(array_diff($childIds, $heldEpcIds)),
        );

        if ($offerableIds === []) {
            return [];
        }

        return Epc::query()
            ->with('ilmd')
            ->whereIn('id', $offerableIds)
            ->get(['id', 'epc_uri', 'sscc18', 'ai_01_21', 'gtin14', 'serial_number', 'epc_type'])
            ->sortBy('id')
            ->mapWithKeys(fn (Epc $epc): array => [(int) $epc->getKey() => EpcBarcodeDisplay::forEpc($epc)])
            ->all();
    }

    /**
     * @param  list<int>|null  $childEpcIds
     * @return list<int>
     */
    public function confirmedParentEpcIdsWithOpenLinks(ReceivingSession $session, ?array $childEpcIds = null): array
    {
        $parentEpcIds = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('line_role', 'parent')
            ->where('status', 'confirmed')
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($parentEpcIds === []) {
            return [];
        }

        $query = AggregationLink::query()
            ->whereIn('parent_epc_id', $parentEpcIds)
            ->whereNull('valid_to');

        if ($childEpcIds !== null && $childEpcIds !== []) {
            $query->whereIn('child_epc_id', array_values(array_unique(array_map('intval', $childEpcIds))));
        }

        return $query
            ->distinct()
            ->pluck('parent_epc_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  list<int|string>|null  $childEpcKeys
     * @return list<int>|null Null means no filter (unpack all).
     */
    public function resolveChildEpcIdFilter(?array $childEpcKeys): ?array
    {
        if ($childEpcKeys === null || $childEpcKeys === []) {
            return null;
        }

        $ids = [];
        $urns = [];

        foreach ($childEpcKeys as $key) {
            if (is_int($key) || (is_string($key) && ctype_digit($key))) {
                $ids[] = (int) $key;

                continue;
            }

            if (is_string($key) && $key !== '') {
                $urns[] = $key;
            }
        }

        if ($urns !== []) {
            $fromUrns = Epc::query()
                ->whereIn('epc_uri', array_values(array_unique($urns)))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $ids = array_merge($ids, $fromUrns);
        }

        $ids = array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0)));

        return $ids === [] ? null : $ids;
    }

    /**
     * @param  list<int>  $childEpcIds
     * @return array{
     *     first_event: ?EpcisEvent,
     *     blocks: list<array{event_id: string, parent_epc_id: int, child_epc_ids: list<int>}>,
     *     closed_links: int
     * }
     */
    private function authorEventsForParent(
        Epc $parent,
        EpcisDocument $document,
        Carbon $eventTime,
        Carbon $recordTime,
        string $timezoneOffset,
        ?string $gln,
        array $childEpcIds,
    ): array {
        $parentId = (int) $parent->getKey();
        $childFilter = array_values(array_unique(array_map('intval', $childEpcIds)));

        $openLinks = AggregationLink::query()
            ->where('parent_epc_id', $parentId)
            ->whereNull('valid_to')
            ->whereIn('child_epc_id', $childFilter)
            ->lockForUpdate()
            ->get(['id', 'parent_epc_id', 'child_epc_id']);

        if ($openLinks->isEmpty()) {
            return ['first_event' => null, 'blocks' => [], 'closed_links' => 0];
        }

        $childIds = $openLinks->map(fn ($link): int => (int) $link->child_epc_id)->values()->all();

        // Re-checked after the row lock: a hold raised while this unpack queued must win.
        $this->custodyGate->assertOperableFor([$parentId, ...$childIds], 'unpacking');

        $this->preflightUnpackAggregationCandidate($parentId, $childIds, $eventTime);

        $eventUuid = 'urn:uuid:'.(string) Str::uuid();

        $event = EpcisEvent::query()->create($this->authoredEventAttributes([
            'document_id' => $document->getKey(),
            'event_id' => $eventUuid,
            'event_type' => 'AggregationEvent',
            'event_time' => $eventTime,
            'record_time' => $recordTime,
            'event_timezone_offset' => $timezoneOffset,
            'action' => 'DELETE',
            'biz_step' => self::BIZ_STEP_UNPACKING,
            'disposition' => self::DISPOSITION_IN_PROGRESS,
            'read_point_gln' => $gln,
            'biz_location_gln' => $gln,
            'trading_partner_id' => null,
        ]));

        $eventEpcRows = [[
            'event_id' => $event->getKey(),
            'epc_id' => $parentId,
            'role' => 'parentID',
        ]];
        foreach ($childIds as $childId) {
            $eventEpcRows[] = [
                'event_id' => $event->getKey(),
                'epc_id' => $childId,
                'role' => 'childEPC',
            ];
        }
        DB::table('event_epcs')->insertOrIgnore($eventEpcRows);

        AggregationLink::query()
            ->whereIn('id', $openLinks->pluck('id')->map(fn ($id): int => (int) $id)->all())
            ->update(['valid_to' => $recordTime]);

        return [
            'first_event' => $event,
            'blocks' => [[
                'event_id' => $eventUuid,
                'parent_epc_id' => $parentId,
                'child_epc_ids' => $childIds,
            ]],
            'closed_links' => $openLinks->count(),
        ];
    }

    private function createUnpackDocument(ReceivingSession $session, Carbon $now): EpcisDocument
    {
        $disk = (string) config('tracepharma.epcis.authored_payload_disk', 'local');
        $documentUuid = (string) Str::uuid();
        $payloadPath = "epcis/outbound/receiving-unpack-{$session->getKey()}-{$now->format('Ymd_His')}.xml";

        $attributes = [
            'document_uuid' => $documentUuid,
            'schema_version' => '1.2',
            'creation_date' => $now,
            'direction' => 'outbound',
            'authored_kind' => EpcisAuthoredKind::SsccDisaggregation,
            'trading_partner_id' => $session->trading_partner_id,
            'format' => 'xml',
            'original_filename' => "receiving-unpack-{$session->getKey()}.xml",
            'payload_disk' => $disk,
            'payload_path' => $payloadPath,
            'dscsa_affirm' => false,
            'status' => 'generated',
            'notes' => "Generated receiving unpack (hierarchy break) for receiving session #{$session->getKey()}.",
            'reprocess_count' => 0,
            'event_count' => 0,
            'epc_count' => 0,
            'received_at' => $now,
        ];

        if (Schema::hasColumn('epcis_documents', 'ingest_generation')) {
            $attributes['ingest_generation'] = 1;
        }

        return EpcisDocument::query()->create($attributes);
    }

    private function createParentUnpackDocument(Epc $parent, Carbon $now, ?int $actorId = null): EpcisDocument
    {
        $disk = (string) config('tracepharma.epcis.authored_payload_disk', 'local');
        $documentUuid = (string) Str::uuid();
        $parentKey = (int) $parent->getKey();
        $payloadPath = "epcis/outbound/parent-unpack-{$parentKey}-{$now->format('Ymd_His')}.xml";

        $notes = "Generated parent unpack (hierarchy break) for EPC #{$parentKey}.";
        if ($actorId !== null) {
            $notes .= " unpacked_by:{$actorId}";
            Log::info('Parent unpack authored', [
                'parent_epc_id' => $parentKey,
                'actor_id' => $actorId,
            ]);
        }

        $attributes = [
            'document_uuid' => $documentUuid,
            'schema_version' => '1.2',
            'creation_date' => $now,
            'direction' => 'outbound',
            'authored_kind' => EpcisAuthoredKind::SsccDisaggregation,
            'trading_partner_id' => null,
            'format' => 'xml',
            'original_filename' => "parent-unpack-{$parentKey}.xml",
            'payload_disk' => $disk,
            'payload_path' => $payloadPath,
            'dscsa_affirm' => false,
            'status' => 'generated',
            'notes' => $notes,
            'reprocess_count' => 0,
            'event_count' => 0,
            'epc_count' => 0,
            'received_at' => $now,
        ];

        if (Schema::hasColumn('epcis_documents', 'ingest_generation')) {
            $attributes['ingest_generation'] = 1;
        }

        return EpcisDocument::query()->create($attributes);
    }

    private function resolveWorkstationSite(): ?Site
    {
        $siteId = CurrentSite::preferredId(
            null,
            EligibleReceiveSites::organizationOptions(),
        );

        if ($siteId === null) {
            return null;
        }

        return Site::query()->find($siteId);
    }

    /**
     * @param  list<int>  $epcIds
     * @return list<int>
     */
    private function openHoldEpcIds(array $epcIds): array
    {
        return app(ReceivingGate::class)->epcIdsBlockedByOpenHold($epcIds);
    }

    private function timezoneOffsetForSite(?Site $site, Carbon $at): string
    {
        $tzName = $site?->timezone ?: (string) config('app.timezone', 'UTC');

        try {
            return $at->clone()->timezone($tzName)->format('P');
        } catch (Throwable) {
            return $at->clone()->utc()->format('P');
        }
    }

    /**
     * @return array{gln: string, sgln_urn: string}
     */
    private function resolveLocationGlnsForSite(?Site $site): array
    {
        $siteGln = Sgln::normalizeGln($site?->gln);

        if ($siteGln !== null) {
            if (ValidGln::normalize($siteGln) === null) {
                throw new DomainException(
                    'Cannot author unpack EPCIS: site GLN fails the GS1 check digit (fix the site GLN so it matches its SGLN).',
                );
            }

            $candidates = [];
            $siteSgln = $site?->getAttribute('sgln');
            if (is_string($siteSgln) && $siteSgln !== '') {
                $candidates[] = $siteSgln;
            }

            $sglnUrn = Sgln::resolveUrn($siteGln, null, $candidates);
            if ($sglnUrn === null) {
                $prefix = TenantSettings::forTenant(tenant())->companyPrefix();
                $sglnUrn = SglnResolution::fromCompanyPrefix($siteGln, $prefix);
            }

            if ($sglnUrn === null) {
                throw new DomainException(
                    'Cannot author unpack EPCIS: site GLN is set but SGLN could not be built (organization company prefix required, or site GLN/SGLN mismatch).',
                );
            }

            return [
                'gln' => $siteGln,
                'sgln_urn' => $sglnUrn,
            ];
        }

        $settings = TenantSettings::forTenant(tenant());
        $tenantGln = Sgln::normalizeGln($settings->gln());

        if ($tenantGln === null) {
            throw new DomainException(
                'Cannot author unpack EPCIS: no site or organization GLN is configured for readPoint/bizLocation.',
            );
        }

        $sglnUrn = SglnResolution::fromCompanyPrefix($tenantGln, $settings->companyPrefix());

        if ($sglnUrn === null) {
            throw new DomainException(
                'Cannot author unpack EPCIS: organization GLN is set but SGLN could not be built (company prefix required).',
            );
        }

        return [
            'gln' => $tenantGln,
            'sgln_urn' => $sglnUrn,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function authoredEventAttributes(array $attributes): array
    {
        if (Schema::hasColumn('epcis_events', 'ingest_generation')) {
            $attributes['ingest_generation'] = 1;
        }

        return $attributes;
    }

    /**
     * @param  list<int>  $childEpcIds
     */
    private function preflightUnpackAggregationCandidate(
        int $parentEpcId,
        array $childEpcIds,
        Carbon $eventTime,
    ): void {
        $neededIds = array_values(array_unique([$parentEpcId, ...$childEpcIds]));
        $epcsById = Epc::query()
            ->whereIn('id', $neededIds)
            ->get(['id', 'epc_uri'])
            ->keyBy('id');

        $parent = $epcsById->get($parentEpcId);
        if ($parent === null) {
            throw new InvalidArgumentException(
                "Unpack preflight: parent EPC #{$parentEpcId} not found.",
            );
        }

        $childUris = [];
        $missingChildIds = [];
        foreach ($childEpcIds as $childId) {
            $epc = $epcsById->get($childId);
            if ($epc === null) {
                $missingChildIds[] = $childId;
            } else {
                $childUris[] = (string) $epc->epc_uri;
            }
        }

        if ($missingChildIds !== []) {
            throw new InvalidArgumentException(
                'Unpack preflight: child EPC(s) not found: '
                .implode(', ', array_map(fn (int $id): string => "#{$id}", $missingChildIds))
                ." (parent EPC #{$parentEpcId}).",
            );
        }

        if ($childUris === []) {
            throw new InvalidArgumentException(
                "Unpack preflight: no child EPCs for parent EPC #{$parentEpcId}.",
            );
        }

        $this->assertCandidate->handle(
            parentUri: (string) $parent->epc_uri,
            childEpcs: $childUris,
            action: EpcisAction::Delete,
            bizStep: 'unpacking',
            disposition: 'in_progress',
            eventTimeUtc: $eventTime->clone()->utc()->toDateTimeImmutable(),
        );
    }

    /**
     * @param  list<array{event_id: string, parent_epc_id: int, child_epc_ids: list<int>}>  $unpackBlocks
     */
    private function buildUnpackXml(
        Carbon $eventTime,
        Carbon $recordTime,
        string $timezoneOffset,
        string $sglnUrn,
        array $unpackBlocks,
    ): string {
        $creationDate = $recordTime->clone()->utc()->format('Y-m-d\TH:i:s.v\Z');
        $eventTimeXml = $eventTime->clone()->utc()->format('Y-m-d\TH:i:s.v\Z');
        $recordTimeXml = $creationDate;
        $offsetXml = htmlspecialchars($timezoneOffset, ENT_XML1);
        $safe = htmlspecialchars($sglnUrn, ENT_XML1);
        $locationXml =
            "        <readPoint>\n".
            "          <id>{$safe}</id>\n".
            "        </readPoint>\n".
            "        <bizLocation>\n".
            "          <id>{$safe}</id>\n".
            "        </bizLocation>\n";

        $neededIds = [];
        foreach ($unpackBlocks as $block) {
            $neededIds[] = $block['parent_epc_id'];
            foreach ($block['child_epc_ids'] as $childId) {
                $neededIds[] = $childId;
            }
        }
        $epcsById = Epc::query()
            ->whereIn('id', array_values(array_unique($neededIds)))
            ->get()
            ->keyBy('id');

        $aggChunks = [];
        foreach ($unpackBlocks as $block) {
            $parent = $epcsById->get($block['parent_epc_id']);
            if ($parent === null) {
                throw new InvalidArgumentException(
                    "Unpack XML: parent EPC #{$block['parent_epc_id']} not found.",
                );
            }

            $childUris = [];
            foreach ($block['child_epc_ids'] as $childId) {
                $epc = $epcsById->get($childId);
                if ($epc !== null) {
                    $childUris[] = (string) $epc->epc_uri;
                }
            }

            if ($childUris === []) {
                throw new InvalidArgumentException(
                    'Unpack XML: no child EPC URIs resolved for parent EPC #'
                    .$block['parent_epc_id']
                    .' (child ids: '
                    .implode(', ', array_map(fn (int $id): string => "#{$id}", $block['child_epc_ids']))
                    .').',
                );
            }

            $parentUri = htmlspecialchars((string) $parent->epc_uri, ENT_XML1);
            $childXml = collect($block['child_epc_ids'])
                ->map(function (int $childId) use ($epcsById): ?string {
                    $epc = $epcsById->get($childId);

                    return $epc !== null
                        ? '          <epc>'.htmlspecialchars((string) $epc->epc_uri, ENT_XML1).'</epc>'
                        : null;
                })
                ->filter()
                ->implode("\n");
            $unpackId = htmlspecialchars($block['event_id'], ENT_XML1);
            $aggChunks[] =
                "              <AggregationEvent>\n".
                "                <eventTime>{$eventTimeXml}</eventTime>\n".
                "                <recordTime>{$recordTimeXml}</recordTime>\n".
                "                <eventTimeZoneOffset>{$offsetXml}</eventTimeZoneOffset>\n".
                "                <baseExtension>\n".
                "                  <eventID>{$unpackId}</eventID>\n".
                "                </baseExtension>\n".
                "                <parentID>{$parentUri}</parentID>\n".
                "                <childEPCs>\n".
                "{$childXml}\n".
                "                </childEPCs>\n".
                "                <action>DELETE</action>\n".
                "                <bizStep>urn:epcglobal:cbv:bizstep:unpacking</bizStep>\n".
                "                <disposition>urn:epcglobal:cbv:disp:in_progress</disposition>\n".
                $locationXml.
                '              </AggregationEvent>';
        }

        $events = implode("\n", $aggChunks);

        return
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n".
            "<epcis:EPCISDocument\n".
            "    xmlns:epcis=\"urn:epcglobal:epcis:xsd:1\"\n".
            "    schemaVersion=\"1.2\"\n".
            "    creationDate=\"{$creationDate}\">\n".
            "  <EPCISBody>\n".
            "    <EventList>\n".
            "{$events}\n".
            "    </EventList>\n".
            "  </EPCISBody>\n".
            "</epcis:EPCISDocument>\n";
    }

    private function timezoneOffset(ReceivingSession $session, Carbon $at): string
    {
        $tzName = $session->site?->timezone ?: (string) config('app.timezone', 'UTC');

        try {
            return $at->clone()->timezone($tzName)->format('P');
        } catch (Throwable) {
            return $at->clone()->utc()->format('P');
        }
    }

    /**
     * @return array{gln: string, sgln_urn: string}
     */
    private function resolveReceivingLocationGlns(ReceivingSession $session): array
    {
        $siteGln = Sgln::normalizeGln($session->site?->gln);

        if ($siteGln !== null) {
            if (ValidGln::normalize($siteGln) === null) {
                throw new DomainException(
                    'Cannot author unpack EPCIS: site GLN fails the GS1 check digit (fix the site GLN so it matches its SGLN).',
                );
            }

            $sglnUrn = $this->resolveSiteSglnUrn($session, $siteGln);
            if ($sglnUrn === null) {
                throw new DomainException(
                    'Cannot author unpack EPCIS: site GLN is set but SGLN could not be built (organization company prefix required, or site GLN/SGLN mismatch).',
                );
            }

            return [
                'gln' => $siteGln,
                'sgln_urn' => $sglnUrn,
            ];
        }

        $settings = TenantSettings::forTenant(tenant());
        $tenantGln = Sgln::normalizeGln($settings->gln());

        if ($tenantGln === null) {
            throw new DomainException(
                'Cannot author unpack EPCIS: no receive-site or organization GLN is configured for readPoint/bizLocation.',
            );
        }

        $sglnUrn = SglnResolution::fromCompanyPrefix($tenantGln, $settings->companyPrefix());

        if ($sglnUrn === null) {
            throw new DomainException(
                'Cannot author unpack EPCIS: organization GLN is set but SGLN could not be built (company prefix required).',
            );
        }

        return [
            'gln' => $tenantGln,
            'sgln_urn' => $sglnUrn,
        ];
    }

    private function resolveSiteSglnUrn(ReceivingSession $session, string $gln): ?string
    {
        $candidates = [];

        $siteSgln = $session->site?->getAttribute('sgln');
        if (is_string($siteSgln) && $siteSgln !== '') {
            $candidates[] = $siteSgln;
        }

        if ($session->epcis_document_id !== null) {
            $fromEvents = DB::table('event_locations')
                ->join('epcis_events', 'epcis_events.id', '=', 'event_locations.event_id')
                ->where('epcis_events.document_id', $session->epcis_document_id)
                ->whereNotNull('event_locations.gln_uri')
                ->pluck('event_locations.gln_uri')
                ->all();
            foreach ($fromEvents as $uri) {
                if (is_string($uri) && $uri !== '') {
                    $candidates[] = $uri;
                }
            }
        }

        $resolved = Sgln::resolveUrn($gln, null, $candidates);
        if ($resolved !== null) {
            return $resolved;
        }

        $prefix = TenantSettings::forTenant(tenant())->companyPrefix();

        return SglnResolution::fromCompanyPrefix($gln, $prefix);
    }
}
