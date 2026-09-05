<?php

namespace App\Actions\Epcis;

use App\Domain\Gs1\CheckDigit;
use App\Domain\Gs1\SgtinUri;
use App\Domain\Gs1\Sscc18;
use App\Domain\Gs1\SsccUri;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EpcisException;
use App\Models\Epcis\EventQuantity;
use App\Support\Epcis\EpcisSchemaVersion;
use App\Support\Epcis\Validation\EpcisCatalogBusinessRules;
use App\Support\Epcis\Validation\EpcisCbvAllowlist;
use App\Support\Epcis\Validation\EpcisJsonSchema20Validator;
use App\Support\Epcis\Validation\EpcisValidationCatalog;
use App\Support\Epcis\Validation\EpcisValidationContext;
use App\Support\Epcis\Validation\EpcisValidationFinding;
use App\Support\Epcis\Validation\EpcisValidationProfileResolver;
use App\Support\Epcis\Validation\EpcisValidationSeverityMap;
use App\Support\Epcis\Validation\EpcisXsdValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Aggressive EPCIS 1.2 post-parse validation: XSD + DB business rules.
 *
 * Clears prior open validation exceptions, rewrites findings, and flips
 * document status to error (blocking) or validated (warnings allowed).
 */
final class ValidateEpcis12Document
{
    /**
     * Exception types owned by this validator (cleared + rewritten each run).
     * Mirrors the full catalog since {@see EpcisCatalogBusinessRules} findings
     * are merged into the same run and must be cleared/rewritten alongside them.
     *
     * @var list<string>
     */
    public const VALIDATION_EXCEPTION_TYPES = EpcisValidationCatalog::CODES;

    private int $maxFindingsPerType = 50;

    /**
     * @var array<string, int>
     */
    private array $findingCounts = [];

    public function __construct(
        private readonly EpcisXsdValidator $xsdValidator,
        private readonly EpcisJsonSchema20Validator $jsonSchema20Validator,
        private readonly EpcisCatalogBusinessRules $catalogRules,
        private readonly EpcisValidationProfileResolver $profileResolver,
    ) {}

    /**
     * Validates a single EPCIS document's XSD structure and business rules.
     *
     * Direction defaults to the document's own `direction` column, but callers
     * may override it — e.g. `handle($doc, $path, 'outbound')` to run this same
     * aggressive pipeline against shipping-side documents regardless of the
     * document's stored direction.
     *
     * @return list<EpcisValidationFinding>
     */
    public function handle(
        EpcisDocument $document,
        ?string $absolutePayloadPath = null,
        ?string $directionOverride = null,
    ): array {
        $path = $absolutePayloadPath;
        $cleanupTemp = false;

        if ($path === null) {
            $path = $document->materializePayloadPath();
            $tempDir = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
            $resolved = realpath($path) ?: $path;
            $cleanupTemp = str_starts_with(
                $resolved,
                rtrim($tempDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR,
            );
        }

        try {
            $ctx = $this->profileResolver->resolve(
                $document,
                $directionOverride ?? (string) $document->direction,
                $path,
            );

            $this->maxFindingsPerType = (int) config('tracepharma.epcis.validation.max_findings_per_type', 50);
            $this->findingCounts = [];

            // Compute all findings first (schema file I/O + DB business/catalog rules are CPU/IO
            // bound and stay outside the transaction); only the clear+persist+status mutation
            // below needs to be atomic.
            $findings = $this->schemaFindings($document, $path);
            $events = $this->activeEvents($document);
            $findings = array_merge($findings, $this->runBusinessRules($document, $events, $ctx));
            $findings = array_merge($findings, $this->catalogRules->validate($ctx, $events));

            DB::transaction(function () use ($document, $findings): void {
                $this->clearPriorValidationExceptions($document);
                $this->persistFindings($document, $findings);
                $this->applyDocumentStatus($document, $findings);
            });

            return $findings;
        } finally {
            if ($cleanupTemp && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @return list<EpcisValidationFinding>
     */
    private function schemaFindings(EpcisDocument $document, string $path): array
    {
        $format = (string) ($document->format ?? EpcisSchemaVersion::FORMAT_XML);

        if ($format === EpcisSchemaVersion::FORMAT_JSON) {
            return $this->jsonSchema20Validator->validateFile($path);
        }

        // EPCIS 2.0 XML: skip 1.2 XSD; structural parse + catalog rules still apply.
        if ((string) $document->schema_version === EpcisSchemaVersion::V20) {
            return [];
        }

        return $this->xsdValidator->validateFile($path);
    }

    /**
     * Appends a finding unless its type has already hit the configured
     * per-document cap, resolving severity via {@see EpcisValidationSeverityMap}
     * so every code emitted from this action shares the same severity policy
     * as {@see EpcisCatalogBusinessRules}.
     *
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function addFinding(
        array &$findings,
        EpcisValidationContext $ctx,
        string $type,
        string $description,
        ?int $eventId = null,
        ?int $epcId = null,
    ): void {
        $count = $this->findingCounts[$type] ?? 0;
        if ($count >= $this->maxFindingsPerType) {
            return;
        }

        $this->findingCounts[$type] = $count + 1;
        $findings[] = new EpcisValidationFinding(
            exceptionType: $type,
            severity: EpcisValidationSeverityMap::severityFor($type, $ctx),
            description: $description,
            eventId: $eventId,
            epcId: $epcId,
        );
    }

    private function clearPriorValidationExceptions(EpcisDocument $document): void
    {
        EpcisException::query()
            ->where('document_id', $document->getKey())
            ->whereIn('exception_type', EpcisValidationCatalog::clearableCodes())
            ->where('status', 'open')
            ->delete();
    }

    /**
     * @param  Collection<int, EpcisEvent>  $events
     * @return list<EpcisValidationFinding>
     */
    private function runBusinessRules(EpcisDocument $document, Collection $events, EpcisValidationContext $ctx): array
    {
        $findings = [];

        foreach ($events as $event) {
            $findings = array_merge($findings, $this->validateEventStructure($event, $ctx));
        }

        $hasShippingEvent = $events->contains(
            fn (EpcisEvent $e) => $e->event_type === 'ObjectEvent' && EpcisCbvAllowlist::isShipping($e->biz_step),
        );
        $requiresDscsaStatement = $ctx->direction === 'inbound'
            || ($ctx->direction === 'outbound' && $hasShippingEvent);

        if ($requiresDscsaStatement && ! $document->dscsa_affirm) {
            $this->addFinding(
                $findings,
                $ctx,
                'MISSING_DSCSA_STATEMENT',
                ucfirst($ctx->direction).' EPCIS document does not affirm the DSCSA transaction statement.',
            );
        }

        $findings = array_merge($findings, $this->validateCheckDigits($document, $ctx));
        $findings = array_merge($findings, $this->validateAggregationIntegrity($document, $events, $ctx));
        $findings = array_merge($findings, $this->validateLotAndExpiry($document, $events, $ctx));

        // AGGREGATION_QUANTITY_MISMATCH requires epcis_events.quantity — column absent here;
        // event_epcs.quantity variant is covered by EpcisCatalogBusinessRules.
        // LOT_MISMATCH / QUANTITY_MISMATCH kept in VALIDATION_EXCEPTION_TYPES for clear/rewrite only.

        return $findings;
    }

    /**
     * @return Collection<int, EpcisEvent>
     */
    private function activeEvents(EpcisDocument $document): Collection
    {
        $query = Schema::hasColumn('epcis_events', 'ingest_generation')
            ? $document->activeEvents()
            : $document->events();

        return $query->orderBy('id')->get();
    }

    /**
     * @return list<EpcisValidationFinding>
     */
    private function validateEventStructure(EpcisEvent $event, EpcisValidationContext $ctx): array
    {
        $findings = [];
        $eventId = (int) $event->getKey();
        $action = (string) ($event->action ?? '');
        $bizStep = $event->biz_step;
        $disposition = $event->disposition;

        if (! EpcisCbvAllowlist::isAllowedAction($action)) {
            $this->addFinding(
                $findings,
                $ctx,
                'INVALID_ACTION',
                'Event has invalid or unsupported action: '.($action !== '' ? $action : '(blank)'),
                $eventId,
            );
        }

        if (filled($bizStep) && ! EpcisCbvAllowlist::isAllowedBizStep($bizStep)) {
            $this->addFinding(
                $findings,
                $ctx,
                'INVALID_BIZSTEP',
                'Event has non-CBV bizStep: '.$bizStep,
                $eventId,
            );
        }

        if (filled($disposition) && ! EpcisCbvAllowlist::isAllowedDisposition($disposition)) {
            $this->addFinding(
                $findings,
                $ctx,
                'INVALID_DISPOSITION',
                'Event has non-CBV disposition: '.$disposition,
                $eventId,
            );
        }

        $isCommissioning = $event->event_type === 'ObjectEvent'
            && strtoupper($action) === 'ADD'
            && EpcisCbvAllowlist::isCommissioning($bizStep);

        if ($isCommissioning) {
            if (blank($event->read_point_gln)) {
                $this->addFinding(
                    $findings,
                    $ctx,
                    'MISSING_MANDATORY_FIELD',
                    'Commissioning event missing readPoint',
                    $eventId,
                );
            }
            if (blank($event->biz_location_gln)) {
                $this->addFinding(
                    $findings,
                    $ctx,
                    'MISSING_MANDATORY_FIELD',
                    'Commissioning event missing bizLocation',
                    $eventId,
                );
            }
        }

        $isPackingAgg = $event->event_type === 'AggregationEvent'
            && strtoupper($action) === 'ADD'
            && EpcisCbvAllowlist::isPacking($bizStep);

        if ($isPackingAgg) {
            if (blank($event->read_point_gln)) {
                $this->addFinding(
                    $findings,
                    $ctx,
                    'MISSING_MANDATORY_FIELD',
                    'Aggregation packing event missing readPoint',
                    $eventId,
                );
            }
            if (blank($event->biz_location_gln)) {
                $this->addFinding(
                    $findings,
                    $ctx,
                    'MISSING_MANDATORY_FIELD',
                    'Aggregation packing event missing bizLocation',
                    $eventId,
                );
            }
        }

        // Shipping ObjectEvents correctly omit bizLocation per the GS1 US IG; the opposite
        // polarity (bizLocation present on a shipping event) is flagged by EpcisCatalogBusinessRules.

        return $findings;
    }

    /**
     * @return list<EpcisValidationFinding>
     */
    private function validateCheckDigits(EpcisDocument $document, EpcisValidationContext $ctx): array
    {
        $findings = [];
        $epcs = $document->epcsQuery()->get(['id', 'epc_uri', 'epc_type', 'gtin14', 'sscc18']);

        foreach ($epcs as $epc) {
            $epcId = (int) $epc->getKey();
            $uri = (string) ($epc->epc_uri ?? '');

            // Domain GS1 hard-gate (value objects) — same check-digit math as Support facades.
            if (str_starts_with(strtolower($uri), 'urn:epc:id:sgtin:')) {
                try {
                    SgtinUri::fromUrn($uri);
                } catch (InvalidArgumentException) {
                    $this->addFinding(
                        $findings,
                        $ctx,
                        'INVALID_EPC_URI',
                        'Invalid SGTIN EPC URI: '.$uri,
                        null,
                        $epcId,
                    );
                }
            }

            if (str_starts_with(strtolower($uri), 'urn:epc:id:sscc:')) {
                try {
                    SsccUri::fromUrn($uri);
                } catch (InvalidArgumentException) {
                    $this->addFinding(
                        $findings,
                        $ctx,
                        'INVALID_EPC_URI',
                        'Invalid SSCC EPC URI: '.$uri,
                        null,
                        $epcId,
                    );
                }
            }

            if (filled($epc->sscc18)) {
                try {
                    Sscc18::fromDigits((string) $epc->sscc18);
                } catch (InvalidArgumentException) {
                    $this->addFinding(
                        $findings,
                        $ctx,
                        'INVALID_SSCC_CHECK_DIGIT',
                        'SSCC check digit invalid for '.$epc->sscc18,
                        null,
                        $epcId,
                    );
                }
            }

            if (filled($epc->gtin14)) {
                $gtin14 = (string) $epc->gtin14;
                $digits = preg_replace('/\D+/', '', $gtin14) ?? '';
                if (strlen($digits) === 14) {
                    $body = substr($digits, 0, 13);
                    $provided = substr($digits, 13, 1);
                    if ($provided !== CheckDigit::mod10($body)) {
                        $this->addFinding(
                            $findings,
                            $ctx,
                            'INVALID_GTIN_CHECK_DIGIT',
                            'GTIN-14 check digit invalid for '.$gtin14,
                            null,
                            $epcId,
                        );
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @param  Collection<int, EpcisEvent>  $events
     * @return list<EpcisValidationFinding>
     */
    private function validateAggregationIntegrity(EpcisDocument $document, Collection $events, EpcisValidationContext $ctx): array
    {
        $findings = [];

        if (! Schema::hasTable('event_epcs')) {
            return $findings;
        }

        $aggregationEventIds = $events
            ->where('event_type', 'AggregationEvent')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($aggregationEventIds !== []) {
            $rolesByEvent = collect();
            foreach (array_chunk($aggregationEventIds, 1000) as $chunk) {
                $rolesByEvent = $rolesByEvent->merge(
                    DB::table('event_epcs')
                        ->whereIn('event_id', $chunk)
                        ->get(['event_id', 'epc_id', 'role', 'quantity']),
                );
            }
            $rolesByEvent = $rolesByEvent->groupBy('event_id');

            $quantityEventIds = [];
            if (Schema::hasTable('event_quantities')) {
                foreach (array_chunk($aggregationEventIds, 1000) as $chunk) {
                    $quantityEventIds = array_merge(
                        $quantityEventIds,
                        EventQuantity::query()
                            ->whereIn('event_id', $chunk)
                            ->whereIn('role', ['childQuantityList', 'quantityList'])
                            ->whereNotNull('epc_class')
                            ->where('epc_class', '!=', '')
                            ->distinct()
                            ->pluck('event_id')
                            ->map(fn ($id) => (int) $id)
                            ->all(),
                    );
                }
                $quantityEventIds = array_fill_keys($quantityEventIds, true);
            }

            foreach ($events->where('event_type', 'AggregationEvent') as $event) {
                $eventId = (int) $event->getKey();
                $rows = $rolesByEvent->get($eventId, collect());
                $hasParent = $rows->contains(fn ($row) => (string) $row->role === 'parentID');
                $childCount = $rows->where('role', 'childEPC')->count();
                $hasChildQuantity = $rows->contains(
                    fn ($row) => $row->quantity !== null && $row->quantity !== '',
                );
                $hasChildQuantityList = isset($quantityEventIds[$eventId]);

                if (! $hasParent) {
                    $this->addFinding(
                        $findings,
                        $ctx,
                        'MISSING_PARENT',
                        'AggregationEvent missing parentID',
                        $eventId,
                    );
                }

                $isPackingAdd = strtoupper((string) $event->action) === 'ADD'
                    && EpcisCbvAllowlist::isPacking($event->biz_step);

                if ($isPackingAdd && $childCount === 0 && ! $hasChildQuantity && ! $hasChildQuantityList) {
                    $this->addFinding(
                        $findings,
                        $ctx,
                        'MISSING_CHILDREN',
                        'Aggregation packing ADD event has no children',
                        $eventId,
                    );
                }
            }
        }

        if (! Schema::hasTable('aggregation_links')) {
            return $findings;
        }

        $documentEpcIds = $this->documentEpcIds($document);
        if ($documentEpcIds === []) {
            return $findings;
        }

        // Chunk whereIn to stay under MySQL's placeholder limit; aggregate
        // parent sets in PHP so parents split across chunks are still detected.
        // Scope to this document's current ingest generation so superseded reprocess
        // links cannot look like dual parents.
        $parentsByChild = [];
        foreach (array_chunk($documentEpcIds, 1000) as $chunk) {
            $rows = DB::table('aggregation_links')
                ->select('child_epc_id', 'parent_epc_id')
                ->whereIn('parent_epc_id', $chunk)
                ->when(
                    Schema::hasColumn('aggregation_links', 'valid_to'),
                    fn ($q) => $q->whereNull('valid_to'),
                )
                ->whereIn('established_by_event_id', function ($query) use ($document): void {
                    $query->select('id')
                        ->from('epcis_events')
                        ->where('document_id', $document->getKey());

                    if (
                        Schema::hasColumn('epcis_events', 'ingest_generation')
                        && Schema::hasColumn('epcis_documents', 'ingest_generation')
                        && filled($document->getAttribute('ingest_generation'))
                    ) {
                        $query->where('ingest_generation', (int) $document->getAttribute('ingest_generation'));
                    }
                })
                ->get();

            foreach ($rows as $row) {
                $parentsByChild[(int) $row->child_epc_id][(int) $row->parent_epc_id] = true;
            }
        }

        foreach ($parentsByChild as $childEpcId => $parents) {
            if (count($parents) <= 1) {
                continue;
            }

            $this->addFinding(
                $findings,
                $ctx,
                'MULTIPLE_PARENTS',
                'Child EPC has multiple active aggregation parents',
                null,
                $childEpcId,
            );
        }

        return $findings;
    }

    /**
     * @param  Collection<int, EpcisEvent>  $events
     * @return list<EpcisValidationFinding>
     */
    private function validateLotAndExpiry(EpcisDocument $document, Collection $events, EpcisValidationContext $ctx): array
    {
        $findings = [];

        if (! Schema::hasTable('epc_ilmd')) {
            return $findings;
        }

        $documentEpcIds = $this->documentEpcIds($document);
        if ($documentEpcIds === []) {
            return $findings;
        }

        $ilmdRows = collect();
        foreach (array_chunk($documentEpcIds, 1000) as $chunk) {
            $ilmdQuery = DB::table('epc_ilmd')
                ->join('epcs', 'epcs.id', '=', 'epc_ilmd.epc_id')
                ->whereIn('epc_ilmd.epc_id', $chunk)
                ->where('epcs.epc_type', 'sgtin')
                ->select([
                    'epc_ilmd.epc_id',
                    'epc_ilmd.lot_number',
                    'epc_ilmd.expiry_date',
                ]);

            if (Schema::hasColumn('epc_ilmd', 'gtin14')) {
                $ilmdQuery->addSelect('epc_ilmd.gtin14');
            } else {
                $ilmdQuery->addSelect('epcs.gtin14');
            }

            $ilmdRows = $ilmdRows->merge($ilmdQuery->get());
        }

        foreach ($ilmdRows as $row) {
            $epcId = (int) $row->epc_id;

            if (blank($row->lot_number)) {
                $this->addFinding(
                    $findings,
                    $ctx,
                    'MISSING_MANDATORY_FIELD',
                    'Commissioned SGTIN missing lot number',
                    null,
                    $epcId,
                );
            }

            if (blank($row->expiry_date)) {
                $this->addFinding(
                    $findings,
                    $ctx,
                    'MISSING_EXPIRY',
                    'Commissioned SGTIN missing expiry date',
                    null,
                    $epcId,
                );
            }
        }

        $expiriesByLot = [];
        foreach ($ilmdRows as $row) {
            $lot = trim((string) ($row->lot_number ?? ''));
            if ($lot === '' || blank($row->expiry_date)) {
                continue;
            }
            $expiry = (string) $row->expiry_date;
            $expiriesByLot[$lot][$expiry] = true;
        }

        foreach ($expiriesByLot as $lot => $expiries) {
            if (count($expiries) > 1) {
                $this->addFinding(
                    $findings,
                    $ctx,
                    'MIXED_EXPIRY_SAME_LOT',
                    'Lot '.$lot.' has mixed expiry dates in this document',
                );
            }
        }

        $shippingEvents = $events->filter(
            fn (EpcisEvent $event) => EpcisCbvAllowlist::isShipping($event->biz_step),
        );

        if ($shippingEvents->isNotEmpty() && Schema::hasTable('event_epcs')) {
            $expiryByEpcId = $ilmdRows
                ->filter(fn ($row) => filled($row->expiry_date))
                ->keyBy(fn ($row) => (int) $row->epc_id);

            $shippingEventIds = $shippingEvents->pluck('id')->map(fn ($id) => (int) $id)->all();
            $shippedLinks = collect();
            foreach (array_chunk($shippingEventIds, 1000) as $chunk) {
                $shippedLinks = $shippedLinks->merge(
                    DB::table('event_epcs')
                        ->join('epcs', 'epcs.id', '=', 'event_epcs.epc_id')
                        ->whereIn('event_epcs.event_id', $chunk)
                        ->where('epcs.epc_type', 'sgtin')
                        ->get(['event_epcs.event_id', 'event_epcs.epc_id']),
                );
            }

            $eventTimeById = $shippingEvents->keyBy(fn (EpcisEvent $e) => (int) $e->getKey());

            foreach ($shippedLinks as $link) {
                $epcId = (int) $link->epc_id;
                $ilmd = $expiryByEpcId->get($epcId);
                if ($ilmd === null) {
                    continue;
                }

                $event = $eventTimeById->get((int) $link->event_id);
                if ($event === null || $event->event_time === null) {
                    continue;
                }

                $eventDate = $event->event_time->toDateString();
                $expiryDate = (string) $ilmd->expiry_date;

                if ($expiryDate < $eventDate) {
                    // EXPIRED_PRODUCT_SHIPPED resolves to 'critical' via the severity map
                    // (no override exists), so this stays a hard-blocking finding.
                    $this->addFinding(
                        $findings,
                        $ctx,
                        'EXPIRED_PRODUCT_SHIPPED',
                        'Shipped SGTIN expired before event time (expiry '.$expiryDate.')',
                        (int) $link->event_id,
                        $epcId,
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<int>
     */
    private function documentEpcIds(EpcisDocument $document): array
    {
        return $document->epcsQuery()
            ->pluck('epcs.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function persistFindings(EpcisDocument $document, array $findings): void
    {
        $grouped = [];
        foreach ($findings as $finding) {
            $type = $finding->exceptionType;
            if (! isset($grouped[$type])) {
                $grouped[$type] = [
                    'severity' => $finding->severity,
                    'descriptions' => [],
                    'event_ids' => [],
                    'epc_ids' => [],
                ];
            }

            $grouped[$type]['severity'] = $this->worseSeverity(
                $grouped[$type]['severity'],
                $finding->severity,
            );
            $grouped[$type]['descriptions'][$finding->description] = $finding->description;
            if ($finding->eventId !== null) {
                $grouped[$type]['event_ids'][$finding->eventId] = $finding->eventId;
            }
            if ($finding->epcId !== null) {
                $grouped[$type]['epc_ids'][$finding->epcId] = $finding->epcId;
            }
        }

        foreach ($grouped as $type => $bundle) {
            $eventIds = array_values($bundle['event_ids']);
            $epcIds = array_values($bundle['epc_ids']);
            $description = Str::limit(implode('; ', array_values($bundle['descriptions'])), 2000);
            $eventId = count($eventIds) === 1 ? $eventIds[0] : null;

            if ($epcIds === []) {
                $persistEventIds = count($eventIds) > 1 ? $eventIds : [$eventId];
                foreach ($persistEventIds as $persistEventId) {
                    EpcisException::query()->create([
                        'document_id' => $document->getKey(),
                        'event_id' => $persistEventId,
                        'epc_id' => null,
                        'exception_type' => $type,
                        'severity' => $bundle['severity'],
                        'description' => $description,
                        'status' => 'open',
                    ]);
                }

                continue;
            }

            $gtinByEpc = DB::table('epcs')->whereIn('id', $epcIds)->pluck('gtin14', 'id');
            $epcByGtin = [];
            foreach ($epcIds as $epcId) {
                $gtin = (string) ($gtinByEpc[$epcId] ?? 'epc:'.$epcId);
                $epcByGtin[$gtin] ??= $epcId;
            }

            foreach ($epcByGtin as $epcId) {
                EpcisException::query()->create([
                    'document_id' => $document->getKey(),
                    'event_id' => $eventId,
                    'epc_id' => $epcId,
                    'exception_type' => $type,
                    'severity' => $bundle['severity'],
                    'description' => $description,
                    'status' => 'open',
                ]);
            }
        }
    }

    private function worseSeverity(string $current, string $candidate): string
    {
        $rank = ['critical' => 4, 'error' => 3, 'warning' => 2, 'info' => 1];

        return ($rank[$candidate] ?? 0) > ($rank[$current] ?? 0) ? $candidate : $current;
    }

    /**
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function applyDocumentStatus(EpcisDocument $document, array $findings): void
    {
        $blocking = array_values(array_filter(
            $findings,
            static fn (EpcisValidationFinding $f): bool => $f->isBlocking(),
        ));

        if ($blocking !== []) {
            $summaries = array_map(
                static fn (EpcisValidationFinding $f): string => $f->description,
                array_slice($blocking, 0, 5),
            );
            $message = Str::limit(implode('; ', $summaries), 2000);

            // Authored outbound (transfer/ship/receive) already wrote custody events.
            // Flipping status to error makes ResolveEpcLastKnownGln ignore those events
            // and strands stock at the prior site after a failed pre-transmit revalidation.
            if ($document->authored_kind !== null) {
                $document->forceFill([
                    'error_message' => $message,
                ])->save();

                return;
            }

            $document->forceFill([
                'status' => 'error',
                'error_message' => $message,
            ])->save();

            return;
        }

        $document->forceFill([
            'status' => 'validated',
            'error_message' => null,
        ])->save();

        app(DispatchEpcisSubscriptions::class)->handle($document, 'validated');
    }
}
