<?php

namespace App\Actions\Epcis;

use App\Actions\Labeling\StampSsccBatchCommissionedFromDocument;
use App\Actions\Receiving\AttachInboundDocumentToShipment;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EpcisException;
use App\Models\Epcis\EpcisUnmatchedGln;
use App\Services\Epcis\EpcisJsonLd20Parser;
use App\Services\Epcis\EpcisXml20Parser;
use App\Services\Epcis\EpcisXmlParser;
use App\Services\Exceptions\ExceptionService;
use App\Support\Epcis\EpcisSchemaVersion;
use App\Support\Epcis\EpcisXmlReader;
use App\Support\Epcis\LiveAcceptedEpcisEventId;
use App\Support\Epcis\PersistPedigreeXmlFragments;
use App\Support\Epcis\Validation\EpcisValidationCatalog;
use App\Support\Fda\DeaRegistration;
use App\Support\Gs1\Gtin;
use App\Support\Gs1\Sgln;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Parse and persist an existing EPCIS document payload into tenant tables.
 *
 * Uses ingest generations: superseded generations are pruned after successful
 * reprocess, keeping only the active projection pointed to by ingest_generation.
 */
final class ProcessEpcisDocument
{
    private const EVENT_BATCH_SIZE = 50;

    private const EPC_UPSERT_CHUNK = 500;

    /** @var array<string, array<string, mixed>> */
    private array $glnCache = [];

    /** @var list<string> */
    private array $droppedEpcUris = [];

    /** @var array<int, string|null> */
    private array $gtinByEpcId = [];

    /** @var array<int, true> */
    private array $ilmdLotMismatchSignaledEpcIds = [];

    /**
     * Shared epc_ilmd lot/expiry conflicts deferred until after soft-signal clear + validation.
     *
     * @var list<array{epc_id: int, existing_lot: mixed, existing_expiry: mixed, incoming_lot: mixed, incoming_expiry: mixed}>
     */
    private array $pendingSharedIlmdLotMismatches = [];

    private const SHARED_ILMD_LOT_MISMATCH_DESCRIPTION_PREFIX = 'Shared EPC ILMD lot/expiry conflict';

    /**
     * Cached epcis_document_locations for the current document ingest generation.
     *
     * @var array{by_uri: array<string, object>, by_gln: array<string, object>}|null
     */
    private ?array $documentLocationLookup = null;

    private ?int $documentLocationLookupDocumentId = null;

    private ?int $documentLocationLookupGeneration = null;

    /** @var list<int> */
    private array $closedDuringAttemptLinkIds = [];

    public function __construct(
        private readonly EpcisXmlParser $xmlParser,
        private readonly EpcisXml20Parser $xml20Parser,
        private readonly EpcisJsonLd20Parser $jsonLd20Parser,
        private readonly MaterializeEpcKeys $materializeEpcKeys,
        private readonly ResolveGlnToMasterData $resolveGln,
        private readonly ResolveProductFromIdentifier $resolveProduct,
    ) {}

    public function handle(EpcisDocument $document): EpcisDocument
    {
        $document = DB::transaction(
            fn (): EpcisDocument => EpcisDocument::query()
                ->whereKey($document->getKey())
                ->lockForUpdate()
                ->firstOrFail(),
        );

        $absolutePath = $document->materializePayloadPath();
        $cleanupTemp = $this->isTempPayloadPath($absolutePath);

        $document->forceFill(['status' => 'parsing', 'error_message' => null])->save();
        ResolveProductFromIdentifier::clearCache();
        $this->glnCache = [];
        $this->droppedEpcUris = [];
        $this->gtinByEpcId = [];
        $this->ilmdLotMismatchSignaledEpcIds = [];
        $this->pendingSharedIlmdLotMismatches = [];
        $this->documentLocationLookup = null;
        $this->documentLocationLookupDocumentId = null;
        $this->documentLocationLookupGeneration = null;
        $this->closedDuringAttemptLinkIds = [];

        $scoutIndexGeneration = null;
        $scoutShouldIndex = false;
        $previousIngestGeneration = null;
        $generation = null;
        /** @var list<int> */
        $priorGenerationOpenLinkIds = [];

        try {
            app(PruneSupersededIngestGenerations::class)->pruneOrphanGenerations($document->refresh());

            $generation = $this->nextIngestGeneration($document);
            app(PruneSupersededIngestGenerations::class)->supersedePriorGenerationsForAttempt($document, $generation);
            $priorGenerationOpenLinkIds = $this->snapshotPriorGenerationOpenLinkIds($document, $generation);
            $this->tentativelyRetirePriorGenerationAggregationLinks($priorGenerationOpenLinkIds);
            $this->clearStaleValidationExceptionsForReprocess($document, $generation);

            $parser = $this->ingestParser($document);
            $uniqueUris = [];
            $header = $parser->parseHeaderAndStream(
                $absolutePath,
                function (array $event) use (&$uniqueUris): void {
                    foreach ($event['epcs'] ?? [] as $epcRef) {
                        $uri = trim((string) ($epcRef['uri'] ?? ''));
                        if ($uri !== '') {
                            $uniqueUris[$uri] = true;
                        }
                    }
                },
            );

            $this->applyParsedHeader($document, $header);
            $this->clearOperationalStaging($document);
            $this->resolveDocumentParties($document, $header);

            $productClasses = $header['product_classes'] ?? [];
            $epcIdByUri = $this->ensureEpcs(array_keys($uniqueUris), $productClasses);
            $this->linkProductsFromVocabulary($productClasses, $epcIdByUri);
            app(PersistEpcisDocumentVocabulary::class)->handle(
                $document,
                $generation,
                $productClasses,
                $header['locations'] ?? [],
                $header['other_vocabulary'] ?? [],
            );
            $this->persistDocumentHeaderJson($document, $header);

            $eventCount = 0;
            $batch = [];
            $documentEpcIds = [];
            $dscsaPromoted = false;

            EpcisEvent::withoutSyncingToSearch(function () use (
                $absolutePath,
                $document,
                $epcIdByUri,
                $generation,
                $parser,
                &$eventCount,
                &$batch,
                &$documentEpcIds,
                &$dscsaPromoted,
            ): void {
                $parser->parseHeaderAndStream(
                    $absolutePath,
                    function (array $eventData) use ($document, $epcIdByUri, $generation, &$eventCount, &$batch, &$documentEpcIds, &$dscsaPromoted): void {
                        if (! $dscsaPromoted) {
                            $bizStep = strtolower((string) ($eventData['biz_step'] ?? ''));
                            if ($bizStep !== '' && str_contains($bizStep, 'shipping')) {
                                app(PromoteDscsaShippingExtensions::class)->handle($document, $eventData);
                                $dscsaPromoted = true;
                            }
                        }

                        $batch[] = $eventData;
                        if (count($batch) < self::EVENT_BATCH_SIZE) {
                            return;
                        }

                        DB::transaction(function () use ($batch, $document, $epcIdByUri, $generation, &$eventCount, &$documentEpcIds): void {
                            foreach ($batch as $row) {
                                if ($this->persistEvent($document, $row, $epcIdByUri, $generation, $documentEpcIds)) {
                                    $eventCount++;
                                }
                            }
                        });
                        $batch = [];
                    },
                );

                if ($batch !== []) {
                    DB::transaction(function () use ($batch, $document, $epcIdByUri, $generation, &$eventCount, &$documentEpcIds): void {
                        foreach ($batch as $row) {
                            if ($this->persistEvent($document, $row, $epcIdByUri, $generation, $documentEpcIds)) {
                                $eventCount++;
                            }
                        }
                    });
                }
            });

            $scoutIndexGeneration = $generation;

            $this->syncDocumentEpcs($document, $generation, $documentEpcIds);

            app(EnrichEpcisDocumentShippingFields::class)->handle(
                $document->refresh(),
                $header['locations'] ?? [],
                $generation,
            );

            $previousIngestGeneration = $document->ingest_generation;

            $document->forceFill([
                'status' => 'parsed',
                'ingest_generation' => $generation,
                'event_count' => $eventCount,
                'epc_count' => count($documentEpcIds),
                'error_message' => null,
            ])->save();

            // Drop stale soft/legacy signals before re-deriving them below, so reprocessing
            // refreshes soft signals instead of being stuck open by early-return checks in
            // the writers (RecordAtpSoftWarning, RecordSbdhOwningPartyMismatch,
            // recordIncompleteProductMasterDataExceptions).
            // Validator owns catalog DSCSA/biz-transaction findings and clears those itself.
            $this->clearSoftSignalExceptions($document);

            $this->recordComplianceExceptions($document, $productClasses, array_keys($uniqueUris));

            if (class_exists(RecordAtpSoftWarning::class)) {
                app(RecordAtpSoftWarning::class)->handle($document);
            }

            if (class_exists(RecordSbdhOwningPartyMismatch::class)) {
                app(RecordSbdhOwningPartyMismatch::class)->handle($document);
            }

            if (class_exists(RecordDestinationGlnMismatch::class)) {
                app(RecordDestinationGlnMismatch::class)->handle($document);
            }

            if (class_exists(RecordScheduledProductMissingDea::class)) {
                app(RecordScheduledProductMissingDea::class)->handle($document);
            }

            app(ValidateEpcis12Document::class)->handle($document, $absolutePath);
            $document->refresh();

            if ($document->status === 'validated') {
                app(StampSsccBatchCommissionedFromDocument::class)->handle($document);
                app(AttachInboundDocumentToShipment::class)
                    ->expandOpenSessionAfterDocumentEligible($document->fresh());
            }

            if ($document->status === 'error') {
                DB::transaction(function () use ($document, $previousIngestGeneration, $priorGenerationOpenLinkIds, $generation): void {
                    $this->restorePriorGenerationAggregationLinksClosedDuringAttempt(
                        array_values(array_unique([...$priorGenerationOpenLinkIds, ...$this->closedDuringAttemptLinkIds])),
                    );
                    $this->closeThisAttemptOpenAggregationLinks($document, $generation);
                    if ($generation !== null) {
                        app(PruneSupersededIngestGenerations::class)->restoreTentativeSupersede($document, $generation);
                    }
                    $document->forceFill(['ingest_generation' => $previousIngestGeneration])->save();
                });
            } else {
                $now = now();
                // Retire prior-generation links only after validation succeeds so a failed
                // reprocess rolls back ingest_generation without leaving stale closures.
                $this->retireSupersededAggregationLinks($document, $generation, $now);
                $document->forceFill([
                    'processed_at' => $now,
                    'last_processed_at' => $now,
                ])->save();
                app(PruneSupersededIngestGenerations::class)->handle($document->refresh());

                // Lossless commissioning/packing + Location/EPCClass XML for outbound TI
                // when the payload file is later missing (inbound EPCIS or Guardian-authored).
                try {
                    app(PersistPedigreeXmlFragments::class)->forDocument($document->refresh(), $absolutePath);
                } catch (Throwable $fragmentError) {
                    Log::warning('epcis.pedigree_fragments.persist_failed', [
                        'document_id' => $document->getKey(),
                        'message' => $fragmentError->getMessage(),
                    ]);
                }

                $scoutShouldIndex = true;
            }

            // Re-close signals that match already-resolved/closed cases (reprocess recreates them).
            app(ExceptionService::class)->closeMatchingSignalsForDocument((int) $document->getKey());

            $this->recordDroppedEpcUriExceptions($document);
            $this->flushSharedIlmdLotMismatchExceptions($document);

            return $document->refresh();
        } catch (Throwable $e) {
            DB::transaction(function () use ($document, $previousIngestGeneration, $priorGenerationOpenLinkIds, $generation): void {
                $this->restorePriorGenerationAggregationLinksClosedDuringAttempt(
                    array_values(array_unique([...$priorGenerationOpenLinkIds, ...$this->closedDuringAttemptLinkIds])),
                );
                $this->closeThisAttemptOpenAggregationLinks($document, $generation);
                if ($generation !== null) {
                    app(PruneSupersededIngestGenerations::class)->restoreTentativeSupersede($document, $generation);
                }
                if ($previousIngestGeneration !== null) {
                    $document->forceFill(['ingest_generation' => $previousIngestGeneration])->save();
                }
            });

            $document->forceFill([
                'status' => 'error',
                'error_message' => Str::limit($e->getMessage(), 2000),
            ])->save();

            EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'exception_type' => 'INGESTION_PARSE_ERROR',
                'severity' => 'error',
                'description' => Str::limit($e->getMessage(), 2000),
                'status' => 'open',
            ]);

            throw $e;
        } finally {
            $document = $document->refresh();

            if ($scoutShouldIndex && $scoutIndexGeneration !== null) {
                try {
                    $this->indexDocumentEvents($document, $scoutIndexGeneration);
                } catch (Throwable) {
                    // Best-effort: do not mask the primary ingest failure.
                }
            }

            if (! $scoutShouldIndex && $scoutIndexGeneration !== null) {
                try {
                    $this->unsearchableDocumentEvents($document, $scoutIndexGeneration);
                } catch (Throwable) {
                    // Best-effort: orphan DB rows are still pruned below.
                }
            }

            if ($document->status === 'error') {
                app(PruneSupersededIngestGenerations::class)->pruneOrphanGenerations($document->refresh());
            }

            if ($cleanupTemp && is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }
    }

    private function isTempPayloadPath(string $path): bool
    {
        $tempDir = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $resolved = realpath($path) ?: $path;

        return str_starts_with($resolved, rtrim($tempDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array{0: int, 1: int}
     *
     * @deprecated Prefer handle(); retained for callers that already parsed a full document.
     */
    public function persistParsedDocument(EpcisDocument $document, array $parsed): array
    {
        ResolveProductFromIdentifier::clearCache();
        $this->glnCache = [];
        $this->droppedEpcUris = [];
        $this->gtinByEpcId = [];
        $this->ilmdLotMismatchSignaledEpcIds = [];
        $this->pendingSharedIlmdLotMismatches = [];
        $this->documentLocationLookup = null;
        $this->documentLocationLookupDocumentId = null;
        $this->documentLocationLookupGeneration = null;
        $generation = $this->nextIngestGeneration($document);
        app(PruneSupersededIngestGenerations::class)->supersedePriorGenerationsForAttempt($document, $generation);

        $this->resolveDocumentParties($document, $parsed);

        $productClasses = $parsed['product_classes'] ?? [];
        $uniqueUris = EpcisXmlReader::collectUniqueEpcUris($parsed['events'] ?? []);
        $epcIdByUri = $this->ensureEpcs($uniqueUris, $productClasses);
        $this->linkProductsFromVocabulary($productClasses, $epcIdByUri);
        app(PersistEpcisDocumentVocabulary::class)->handle(
            $document,
            $generation,
            $productClasses,
            $parsed['locations'] ?? [],
            $parsed['other_vocabulary'] ?? [],
        );
        $this->persistDocumentHeaderJson($document, $parsed);

        $eventCount = 0;
        $documentEpcIds = [];
        EpcisEvent::withoutSyncingToSearch(function () use (
            $parsed,
            $document,
            $epcIdByUri,
            $generation,
            &$eventCount,
            &$documentEpcIds,
        ): void {
            foreach (array_chunk($parsed['events'] ?? [], self::EVENT_BATCH_SIZE) as $batch) {
                DB::transaction(function () use ($batch, $document, $epcIdByUri, $generation, &$eventCount, &$documentEpcIds): void {
                    foreach ($batch as $eventData) {
                        if ($this->persistEvent($document, $eventData, $epcIdByUri, $generation, $documentEpcIds)) {
                            $eventCount++;
                        }
                    }
                });
            }
        });

        $this->syncDocumentEpcs($document, $generation, $documentEpcIds);

        $this->retireSupersededAggregationLinks($document, $generation, now());

        app(EnrichEpcisDocumentShippingFields::class)->handle(
            $document,
            $parsed['locations'] ?? [],
            $generation,
        );

        $document->forceFill([
            'ingest_generation' => $generation,
            'event_count' => $eventCount,
            'epc_count' => count($documentEpcIds),
        ])->save();

        app(PruneSupersededIngestGenerations::class)->handle($document->refresh());

        $this->indexDocumentEvents($document, $generation);

        return [$eventCount, count($documentEpcIds)];
    }

    private function indexDocumentEvents(EpcisDocument $document, int $generation): void
    {
        $query = EpcisEvent::query()->where('document_id', $document->getKey());

        if (Schema::hasColumn('epcis_events', 'ingest_generation')) {
            $query->where('ingest_generation', $generation);
        }

        $query->orderBy('id')->chunkById(100, function ($events): void {
            $events->searchable();
        });
    }

    private function unsearchableDocumentEvents(EpcisDocument $document, int $generation): void
    {
        $query = EpcisEvent::query()->where('document_id', $document->getKey());

        if (Schema::hasColumn('epcis_events', 'ingest_generation')) {
            $query->where('ingest_generation', $generation);
        }

        $query->orderBy('id')->chunkById(
            (int) config('scout.chunk.unsearchable', 500),
            function ($events): void {
                $events->unsearchable();
            },
        );
    }

    private function nextIngestGeneration(EpcisDocument $document): int
    {
        if (! Schema::hasColumn('epcis_events', 'ingest_generation')) {
            return 1;
        }

        $max = (int) EpcisEvent::query()
            ->where('document_id', $document->getKey())
            ->max('ingest_generation');

        return $max > 0 ? $max + 1 : 1;
    }

    /**
     * @return list<int>
     */
    private function snapshotPriorGenerationOpenLinkIds(EpcisDocument $document, int $newGeneration): array
    {
        if (
            $newGeneration <= 1
            || ! Schema::hasTable('aggregation_links')
            || ! Schema::hasColumn('aggregation_links', 'valid_to')
            || ! Schema::hasColumn('epcis_events', 'ingest_generation')
        ) {
            return [];
        }

        return DB::table('aggregation_links')
            ->whereNull('valid_to')
            ->whereIn('established_by_event_id', function ($query) use ($document, $newGeneration): void {
                $query->select('id')
                    ->from('epcis_events')
                    ->where('document_id', $document->getKey())
                    ->where('ingest_generation', '<', $newGeneration);
            })
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Tentatively close same-document prior-generation open links so a corrected
     * reprocess can establish ADDs whose event_time is earlier than the prior
     * projection's links. Restored on failure via {@see restorePriorGenerationAggregationLinksClosedDuringAttempt}.
     *
     * @param  list<int>  $linkIds
     */
    private function tentativelyRetirePriorGenerationAggregationLinks(array $linkIds): void
    {
        if ($linkIds === [] || ! Schema::hasTable('aggregation_links')) {
            return;
        }

        DB::table('aggregation_links')
            ->whereIn('id', $linkIds)
            ->whereNull('valid_to')
            ->update(['valid_to' => now()->format('Y-m-d H:i:s.u')]);
    }

    /**
     * Drop open catalog validation findings before a reprocess so a failed parse
     * does not leave stale PACK_HIERARCHY / business-rule rows from the prior generation.
     */
    private function clearStaleValidationExceptionsForReprocess(EpcisDocument $document, int $generation): void
    {
        if ($generation <= 1 || ! Schema::hasTable('epcis_exceptions')) {
            return;
        }

        EpcisException::query()
            ->where('document_id', $document->getKey())
            ->whereIn('exception_type', EpcisValidationCatalog::clearableCodes())
            ->where('status', 'open')
            ->delete();
    }

    /**
     * Close open links matching $constraints and remember their IDs so a failed
     * ingest can restore packing / other-document links this attempt closed.
     *
     * @param  array{parent_epc_id?: int, child_epc_id?: list<int>}  $constraints
     */
    private function closeOpenAggregationLinksDuringAttempt(array $constraints, string $validTo): void
    {
        if (! Schema::hasTable('aggregation_links') || ! Schema::hasColumn('aggregation_links', 'valid_to')) {
            return;
        }

        $query = DB::table('aggregation_links')
            ->whereNull('valid_to')
            ->where('valid_from', '<=', $validTo);

        if (isset($constraints['parent_epc_id'])) {
            $query->where('parent_epc_id', $constraints['parent_epc_id']);
        }

        if (isset($constraints['child_epc_id'])) {
            $query->whereIn('child_epc_id', $constraints['child_epc_id']);
        }

        $ids = $query->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return;
        }

        $this->closedDuringAttemptLinkIds = array_values(array_unique([
            ...$this->closedDuringAttemptLinkIds,
            ...$ids,
        ]));

        DB::table('aggregation_links')
            ->whereIn('id', $ids)
            ->update(['valid_to' => $validTo]);
    }

    /**
     * Close open aggregation_links established by this ingest attempt's events.
     * Failed first ingest keeps events for the document UI but must not leave
     * shipping/custody walking open links from an invalid document.
     */
    private function closeThisAttemptOpenAggregationLinks(EpcisDocument $document, ?int $generation): void
    {
        if (
            $generation === null
            || ! Schema::hasTable('aggregation_links')
            || ! Schema::hasColumn('aggregation_links', 'valid_to')
        ) {
            return;
        }

        $eventIds = DB::table('epcis_events')
            ->select('id')
            ->where('document_id', $document->getKey());

        if (Schema::hasColumn('epcis_events', 'ingest_generation')) {
            $eventIds->where('ingest_generation', $generation);
        }

        DB::table('aggregation_links')
            ->whereNull('valid_to')
            ->whereIn('established_by_event_id', $eventIds)
            ->update(['valid_to' => now()]);
    }

    /**
     * @param  list<int>  $linkIds
     */
    private function restorePriorGenerationAggregationLinksClosedDuringAttempt(array $linkIds): void
    {
        if (
            $linkIds === []
            || ! Schema::hasTable('aggregation_links')
            || ! Schema::hasColumn('aggregation_links', 'valid_to')
        ) {
            return;
        }

        foreach (array_chunk($linkIds, 1000) as $chunk) {
            DB::table('aggregation_links')
                ->whereIn('id', $chunk)
                ->whereNotNull('valid_to')
                ->update(['valid_to' => null]);
        }
    }

    /**
     * Retire open aggregation_links established by prior ingest generations of this document.
     *
     * @return int Rows updated
     */
    private function retireSupersededAggregationLinks(
        EpcisDocument $document,
        int $newGeneration,
        \DateTimeInterface $retiredAt,
    ): int {
        if (
            $newGeneration <= 1
            || ! Schema::hasTable('aggregation_links')
            || ! Schema::hasColumn('aggregation_links', 'valid_to')
            || ! Schema::hasColumn('epcis_events', 'ingest_generation')
        ) {
            return 0;
        }

        $timestamp = $retiredAt instanceof \DateTimeInterface
            ? $retiredAt->format('Y-m-d H:i:s.u')
            : (string) $retiredAt;

        return (int) DB::table('aggregation_links')
            ->whereNull('valid_to')
            ->whereIn('established_by_event_id', function ($query) use ($document, $newGeneration): void {
                $query->select('id')
                    ->from('epcis_events')
                    ->where('document_id', $document->getKey())
                    ->where('ingest_generation', '<', $newGeneration);
            })
            ->update(['valid_to' => $timestamp]);
    }

    private function clearOperationalStaging(EpcisDocument $document): void
    {
        if (Schema::hasTable('epcis_unmatched_glns')) {
            EpcisUnmatchedGln::query()->where('document_id', $document->getKey())->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function persistDocumentHeaderJson(EpcisDocument $document, array $parsed): void
    {
        if (! Schema::hasColumn('epcis_documents', 'header_json')) {
            return;
        }

        $headerJson = $parsed['header_json'] ?? null;
        if (! is_array($headerJson) || $headerJson === []) {
            return;
        }

        $document->forceFill(['header_json' => $headerJson])->save();
    }

    /**
     * Select edge parser by stored payload format and schema version.
     * JSON → JSON-LD 2.0 parser; XML 2.0 → Xml20Parser; else XML 1.2 parser.
     */
    private function ingestParser(EpcisDocument $document): EpcisXmlParser|EpcisXml20Parser|EpcisJsonLd20Parser
    {
        $format = (string) ($document->format ?? EpcisSchemaVersion::FORMAT_XML);

        if ($format === EpcisSchemaVersion::FORMAT_JSON) {
            return $this->jsonLd20Parser;
        }

        if ((string) $document->schema_version === EpcisSchemaVersion::V20) {
            return $this->xml20Parser;
        }

        return $this->xmlParser;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function applyParsedHeader(EpcisDocument $document, array $parsed): void
    {
        $sha256 = (string) $document->file_sha256;
        [$documentUuid, $synthesized] = $this->resolveDocumentUuid(
            (string) ($parsed['document_uuid'] ?? ''),
            $sha256,
            $document,
        );

        $dscsaAffirm = (bool) ($parsed['dscsa_affirm'] ?? false);

        $attributes = [
            'document_uuid' => $documentUuid,
            'schema_version' => $parsed['schema_version'] ?? $document->schema_version ?? '1.2',
            'creation_date' => $this->normalizeDateTime($parsed['creation_date']) ?? $document->creation_date ?? now(),
            'dscsa_affirm' => $dscsaAffirm,
            'legal_notice' => $parsed['legal_notice'] ?? null,
        ];

        if (Schema::hasColumn('epcis_documents', 'direct_purchase_statement')) {
            $attributes['direct_purchase_qualifier'] = null;
            $attributes['direct_purchase_statement'] = null;
            $attributes['direct_purchase_indirect_epc_uris'] = null;
            $attributes['received_prev_wholesaler_qualifier'] = null;
            $attributes['received_prev_wholesaler_statement'] = null;
            $attributes['received_prev_wholesaler_indirect_epc_uris'] = null;
        }

        if (Schema::hasColumn('epcis_documents', 'document_uuid_synthesized')) {
            $attributes['document_uuid_synthesized'] = $synthesized;
        }

        if (Schema::hasColumn('epcis_documents', 'sender_gln')) {
            $attributes['sender_gln'] = $parsed['sender_gln'] ?? null;
            $attributes['receiver_gln'] = $parsed['receiver_gln'] ?? null;
        }

        $document->forceFill($attributes)->save();
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function resolveDocumentUuid(string $fromFile, string $sha256, EpcisDocument $document): array
    {
        $synthesized = trim($fromFile) === '';
        $candidate = $synthesized
            ? (string) ($document->document_uuid ?: Str::uuid())
            : trim($fromFile);
        $candidate = Str::limit($candidate, 128, '');

        $existing = EpcisDocument::query()
            ->where('document_uuid', $candidate)
            ->whereKeyNot($document->getKey())
            ->first();

        if ($existing === null) {
            return [$candidate, $synthesized];
        }

        $suffix = '-'.substr($sha256 !== '' ? $sha256 : (string) $document->getKey(), 0, 8);
        $base = Str::limit($candidate, max(1, 128 - strlen($suffix)), '');

        return [$base.$suffix, $synthesized];
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function resolveDocumentParties(EpcisDocument $document, array $parsed): void
    {
        $senderGln = filled($parsed['sender_gln'] ?? null) ? (string) $parsed['sender_gln'] : null;
        $receiverGln = filled($parsed['receiver_gln'] ?? null) ? (string) $parsed['receiver_gln'] : null;

        if ($senderGln !== null) {
            $resolved = $this->cachedResolveGln($senderGln);
            if ($document->trading_partner_id === null && $resolved['trading_partner_id'] !== null) {
                $document->trading_partner_id = $resolved['trading_partner_id'];
                $document->save();
            }

            if (! $this->isKnownParty($resolved)) {
                $this->recordUnmatchedGln(
                    $document,
                    $senderGln,
                    null,
                    'sender',
                    $resolved['trading_partner_id'],
                    $resolved['site_id'],
                );
            }
        }

        if ($receiverGln !== null) {
            $resolved = $this->cachedResolveGln($receiverGln);
            if (! $this->isKnownParty($resolved)) {
                $this->recordUnmatchedGln(
                    $document,
                    $receiverGln,
                    null,
                    'receiver',
                    $resolved['trading_partner_id'],
                    $resolved['site_id'],
                );
            }
        }
    }

    /**
     * @param  list<string>  $uris
     * @param  list<array{idpat?: string, ndc11?: string|null, name?: string|null}>  $productClasses
     * @return array<string, int>
     */
    private function ensureEpcs(array $uris, array $productClasses = []): array
    {
        $ndcByCompanyItem = $this->ndcMapFromProductClasses($productClasses);
        $now = now()->format('Y-m-d H:i:s.u');
        $rows = [];

        foreach ($uris as $uri) {
            $ndc11 = null;
            if (preg_match('/^urn:epc:id:sgtin:(\d+)\.(\d+)\./', $uri, $matches) === 1) {
                $ndc11 = $ndcByCompanyItem[$matches[1].'.'.$matches[2]] ?? null;
            }

            $attrs = $this->materializeEpcKeys->handle($uri, $ndc11);
            if ($attrs === null) {
                $this->droppedEpcUris[] = $uri;

                continue;
            }

            $rows[] = [
                'epc_uri' => $attrs['epc_uri'],
                'epc_type' => $attrs['epc_type'],
                'company_prefix' => $attrs['company_prefix'],
                'indicator_digit' => $attrs['indicator_digit'] ?? null,
                'item_reference' => $attrs['item_reference'] ?? null,
                'serial_number' => $attrs['serial_number'] ?? null,
                'extension_digit' => $attrs['extension_digit'] ?? null,
                'gtin14' => $attrs['gtin14'] ?? null,
                'sscc18' => $attrs['sscc18'] ?? null,
                'ai_01_21' => $attrs['ai_01_21'] ?? null,
                'ai_00' => $attrs['ai_00'] ?? null,
                'product_id' => $attrs['product_id'] ?? null,
                'first_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, self::EPC_UPSERT_CHUNK) as $chunk) {
            Epc::query()->upsert(
                $chunk,
                ['epc_uri'],
                [
                    'epc_type',
                    'company_prefix',
                    'indicator_digit',
                    'item_reference',
                    'serial_number',
                    'extension_digit',
                    'gtin14',
                    'sscc18',
                    'ai_01_21',
                    'ai_00',
                    // Fill null product_id when newly resolved; never wipe non-null to null.
                    'product_id' => DB::raw('COALESCE(VALUES(`product_id`), `product_id`)'),
                    'updated_at',
                ],
            );
        }

        $map = [];
        foreach (array_chunk(array_column($rows, 'epc_uri'), self::EPC_UPSERT_CHUNK) as $uriChunk) {
            $found = Epc::query()
                ->whereIn('epc_uri', $uriChunk)
                ->get(['id', 'epc_uri', 'gtin14']);

            foreach ($found as $epc) {
                $id = (int) $epc->id;
                $map[(string) $epc->epc_uri] = $id;
                $this->gtinByEpcId[$id] = $epc->gtin14;
            }
        }

        return $map;
    }

    /**
     * @param  list<array{idpat?: string, ndc11?: string|null, name?: string|null}>  $productClasses
     * @return array<string, string>
     */
    private function ndcMapFromProductClasses(array $productClasses): array
    {
        $map = [];

        foreach ($productClasses as $class) {
            $ndc11 = $class['ndc11'] ?? null;
            $idpat = (string) ($class['idpat'] ?? '');
            if (! filled($ndc11) || preg_match('/^urn:epc:idpat:sgtin:(\d+)\.(\d+)\.\*$/', $idpat, $matches) !== 1) {
                continue;
            }

            // Keyed on indicator digit + item reference only. A cross-indicator key
            // would hand a case EPC the unit's NDC-11 (or vice versa) and steer the
            // product link to the wrong packaging level.
            $map[$matches[1].'.'.$matches[2]] = (string) $ndc11;
        }

        return $map;
    }

    /**
     * Link EPCs carried by this document to products declared in its EPCClass vocabulary.
     *
     * Scoped to the EPCs in this document and to the exact indicator digit named by the
     * idpat. Updating every EPC that shares a company prefix + item reference would
     * rewrite unrelated tenant history and move case EPCs onto unit products.
     *
     * @param  list<array{idpat?: string, ndc11?: string|null, name?: string|null}>  $productClasses
     * @param  array<string, int>  $epcIdByUri
     */
    private function linkProductsFromVocabulary(array $productClasses, array $epcIdByUri): void
    {
        $epcIds = array_values(array_unique(array_values($epcIdByUri)));

        if ($epcIds === []) {
            return;
        }

        foreach ($productClasses as $class) {
            $idpat = (string) ($class['idpat'] ?? '');
            if (preg_match('/^urn:epc:idpat:sgtin:(\d+)\.(\d+)\.\*$/', $idpat, $matches) !== 1) {
                continue;
            }

            $companyPrefix = $matches[1];
            $indicatorDigit = substr($matches[2], 0, 1);
            $itemReference = substr($matches[2], 1);
            $ndc11 = filled($class['ndc11'] ?? null) ? (string) $class['ndc11'] : null;

            $product = $this->resolveProduct->handle(
                gtin14: $this->gtin14FromIdpatParts($companyPrefix, $matches[2]),
                ndc11: $ndc11,
            );

            if ($product === null) {
                continue;
            }

            foreach (array_chunk($epcIds, self::EPC_UPSERT_CHUNK) as $chunk) {
                Epc::query()
                    ->whereIn('id', $chunk)
                    ->where('epc_type', 'sgtin')
                    ->where('company_prefix', $companyPrefix)
                    ->where('indicator_digit', $indicatorDigit)
                    ->where('item_reference', $itemReference)
                    ->where(function ($query) use ($product): void {
                        $query->whereNull('product_id')
                            ->orWhere('product_id', '!=', $product->getKey());
                    })
                    ->update(['product_id' => $product->getKey()]);
            }
        }
    }

    /**
     * GTIN-14 for an idpat's company prefix + indicator/item reference.
     */
    private function gtin14FromIdpatParts(string $companyPrefix, string $indicatorItem): ?string
    {
        $body = substr($indicatorItem, 0, 1).$companyPrefix.substr($indicatorItem, 1);

        if (strlen($body) !== 13 || ! ctype_digit($body)) {
            return null;
        }

        return $body.Gtin::checkDigit($body);
    }

    /**
     * @param  array<string, mixed>  $eventData
     * @param  array<string, int>  $epcIdByUri
     * @param  array<int, true>  $documentEpcIds
     */
    private function persistEvent(
        EpcisDocument $document,
        array $eventData,
        array $epcIdByUri,
        int $generation,
        array &$documentEpcIds,
    ): bool {
        $readPointGln = null;
        if (filled($eventData['read_point_uri'] ?? null)) {
            $readPointGln = Sgln::fromUrn((string) $eventData['read_point_uri'])['gln'] ?? null;
        }

        $bizLocationGln = null;
        if (filled($eventData['biz_location_uri'] ?? null)) {
            $bizLocationGln = Sgln::fromUrn((string) $eventData['biz_location_uri'])['gln'] ?? null;
        }

        $gs1EventId = filled($eventData['event_id'] ?? null)
            ? Str::limit((string) $eventData['event_id'], 128, '')
            : null;

        if (
            filled($gs1EventId)
            && app(LiveAcceptedEpcisEventId::class)->existsOnOtherDocument($gs1EventId, (int) $document->getKey())
        ) {
            return false;
        }

        $errorDeclaration = $eventData['error_declaration'] ?? null;
        $correctiveIds = null;
        if (is_array($errorDeclaration) && isset($errorDeclaration['corrective_event_ids'])) {
            $correctiveIds = $errorDeclaration['corrective_event_ids'];
        }

        $create = [
            'document_id' => $document->getKey(),
            'event_type' => (string) $eventData['event_type'],
            'event_time' => $this->normalizeDateTime($eventData['event_time'] ?? null) ?? now(),
            'event_timezone_offset' => $eventData['event_timezone_offset'] ?? null,
            'action' => (string) ($eventData['action'] ?? 'ADD'),
            'biz_step' => $eventData['biz_step'] ?? null,
            'disposition' => $eventData['disposition'] ?? null,
            'read_point_gln' => $readPointGln,
            'biz_location_gln' => $bizLocationGln,
            'trading_partner_id' => $document->trading_partner_id,
        ];

        if (Schema::hasColumn('epcis_events', 'ingest_generation')) {
            $create['ingest_generation'] = $generation;
        }
        if (Schema::hasColumn('epcis_events', 'event_id')) {
            $create['event_id'] = $gs1EventId;
        }
        if (Schema::hasColumn('epcis_events', 'record_time')) {
            $create['record_time'] = $this->normalizeDateTime($eventData['record_time'] ?? null);
        }
        if (Schema::hasColumn('epcis_events', 'error_declaration') && is_array($errorDeclaration)) {
            $create['error_declaration'] = $errorDeclaration;
        }
        if (Schema::hasColumn('epcis_events', 'corrective_event_ids') && is_array($correctiveIds)) {
            $create['corrective_event_ids'] = $correctiveIds;
        }

        $extensionJson = is_array($eventData['extension_json'] ?? null)
            ? $eventData['extension_json']
            : [];
        if (filled($eventData['transformation_id'] ?? null)) {
            $extensionJson['transformation_id'] = (string) $eventData['transformation_id'];
        }
        if (Schema::hasColumn('epcis_events', 'extension_json') && $extensionJson !== []) {
            $create['extension_json'] = $extensionJson;
        }

        if (
            Schema::hasColumn('epcis_events', 'persistent_disposition')
            && array_key_exists('persistent_disposition', $eventData)
            && $eventData['persistent_disposition'] !== null
        ) {
            $persistentDisposition = $eventData['persistent_disposition'];
            $create['persistent_disposition'] = is_array($persistentDisposition)
                ? Str::limit(json_encode($persistentDisposition, JSON_THROW_ON_ERROR), 255, '')
                : Str::limit((string) $persistentDisposition, 255, '');
        }

        $event = EpcisEvent::query()->create($create);

        $eventId = (int) $event->getKey();
        $eventEpcs = [];
        $parentEpcId = null;
        $childEpcIds = [];

        foreach ($eventData['epcs'] ?? [] as $epcRef) {
            $uri = (string) ($epcRef['uri'] ?? '');
            $role = (string) ($epcRef['role'] ?? 'epcList');
            $epcId = $epcIdByUri[$uri] ?? null;
            if ($epcId === null) {
                continue;
            }

            $documentEpcIds[$epcId] = true;

            $eventEpcs[] = [
                'event_id' => $eventId,
                'epc_id' => $epcId,
                'role' => $role,
                'quantity' => $epcRef['quantity'] ?? null,
                'uom' => $epcRef['uom'] ?? null,
            ];

            if ($role === 'parentID') {
                $parentEpcId = $epcId;
            } elseif (in_array($role, ['childEPC', 'inputEPC', 'outputEPC'], true)) {
                if ($role === 'childEPC') {
                    $childEpcIds[] = $epcId;
                }
            }
        }

        foreach (array_chunk($eventEpcs, 1000) as $chunk) {
            DB::table('event_epcs')->insert($chunk);
        }

        $action = (string) ($eventData['action'] ?? 'ADD');
        $validFrom = $event->event_time?->format('Y-m-d H:i:s.u') ?? now()->format('Y-m-d H:i:s.u');

        if ($action === 'ADD' && $parentEpcId !== null && $childEpcIds !== []) {
            // Serialize concurrent ADDs on the same child: lock child EPC rows
            // (ordered by id to avoid deadlocks) before close+open so two writers
            // cannot both observe zero open parents and insert dual open links.
            $sortedChildIds = array_values(array_unique($childEpcIds));
            sort($sortedChildIds);

            foreach (array_chunk($sortedChildIds, 1000) as $chunk) {
                DB::table('epcs')
                    ->whereIn('id', $chunk)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);
            }

            // A child may have at most one open parent: close any prior open links
            // (any parent / any establishing document) before inserting the new ones.
            // Only close links established at or before this event's time — a backdated
            // ADD must never invert a newer open link by setting valid_to < valid_from.
            foreach (array_chunk($sortedChildIds, 1000) as $chunk) {
                $hasNewerOpenLink = DB::table('aggregation_links')
                    ->whereIn('child_epc_id', $chunk)
                    ->whereNull('valid_to')
                    ->where('valid_from', '>', $validFrom)
                    ->exists();

                if ($hasNewerOpenLink) {
                    throw new DomainException(
                        'Aggregation ADD at '.$validFrom
                        .' cannot establish a parent: a newer open link already exists for one or more child EPCs.',
                    );
                }
            }

            foreach (array_chunk($sortedChildIds, 1000) as $chunk) {
                $this->closeOpenAggregationLinksDuringAttempt(
                    ['child_epc_id' => $chunk],
                    $validFrom,
                );
            }

            $linkRows = [];
            foreach ($sortedChildIds as $childEpcId) {
                $linkRows[] = [
                    'parent_epc_id' => $parentEpcId,
                    'child_epc_id' => $childEpcId,
                    'established_by_event_id' => $eventId,
                    'link_type' => 'aggregation',
                    'valid_from' => $validFrom,
                    'valid_to' => null,
                    'created_at' => now()->format('Y-m-d H:i:s.u'),
                ];
            }

            foreach (array_chunk($linkRows, 1000) as $chunk) {
                DB::table('aggregation_links')->insertOrIgnore($chunk);
            }
        }

        if ($action === 'DELETE' && $parentEpcId !== null && $childEpcIds !== []) {
            // Close open (parent, child) links regardless of which document established them.
            // Never invert a newer open link with an older DELETE's event_time.
            foreach (array_chunk($childEpcIds, 1000) as $chunk) {
                $this->closeOpenAggregationLinksDuringAttempt(
                    [
                        'parent_epc_id' => $parentEpcId,
                        'child_epc_id' => $chunk,
                    ],
                    $validFrom,
                );
            }
        } elseif ($action === 'DELETE' && $parentEpcId !== null && $childEpcIds === []) {
            // Empty childEPCs on a DELETE means disaggregate-all: close every open link
            // under this parent, subject to the same no-inversion rule as above.
            $this->closeOpenAggregationLinksDuringAttempt(
                ['parent_epc_id' => $parentEpcId],
                $validFrom,
            );
        }

        $this->persistLocationsAndParties($document, $eventId, $eventData, $generation);
        $this->persistBizTransactions($eventId, $eventData);
        $this->persistQuantities($eventId, $eventData);
        $this->persistIlmd($document, $eventId, $eventData, $epcIdByUri);

        return true;
    }

    /**
     * @param  array<string, mixed>  $eventData
     */
    private function persistLocationsAndParties(
        EpcisDocument $document,
        int $eventId,
        array $eventData,
        int $generation,
    ): void {
        $locationRows = [];
        foreach (['read_point_uri' => 'readPoint', 'biz_location_uri' => 'bizLocation'] as $key => $locationType) {
            if (! filled($eventData[$key] ?? null)) {
                continue;
            }

            $uri = (string) $eventData[$key];
            $location = $this->resolveEpcisLocationToken($uri);
            $resolved = $location['resolved'];

            if (! $this->hasAnyMasterData($resolved)) {
                $unmatchedGln = $location['parsed_gln'] ?? $location['persisted_gln'] ?? '';
                if ($unmatchedGln !== '') {
                    $this->recordUnmatchedGln($document, $unmatchedGln, $uri, $locationType, null, null);
                }
            }

            $overlay = $this->documentLocationOverlay($document, $generation, $location['overlay_gln'], $uri);

            $locationRows[] = [
                'event_id' => $eventId,
                'location_type' => $locationType,
                'gln' => $location['persisted_gln'],
                'gln_uri' => $uri,
                'name' => $overlay['name'],
                'street_address' => $overlay['street_address'],
                'city' => $overlay['city'],
                'state' => $overlay['state'],
                'postal_code' => $overlay['postal_code'],
                'country_code' => $overlay['country_code'],
                'latitude' => null,
                'longitude' => null,
                'site_id' => $resolved['site_id'] ?? null,
                'location_device_id' => $resolved['location_device_id'] ?? null,
                'read_point_id' => $resolved['read_point_id'] ?? null,
                'extra_json' => null,
            ];
        }

        if ($locationRows !== []) {
            DB::table('event_locations')->insert($locationRows);
        }

        $partyRows = [];
        foreach ($eventData['parties'] ?? [] as $party) {
            $glnUri = (string) ($party['gln_uri'] ?? '');
            $context = (string) ($party['party_role'] ?? 'source');

            if ($glnUri === '') {
                continue;
            }

            $location = $this->resolveEpcisLocationToken($glnUri);
            $resolved = $location['resolved'];

            if (! $this->hasAnyMasterData($resolved)) {
                $unmatchedGln = $location['parsed_gln'] ?? $location['persisted_gln'] ?? '';
                if ($unmatchedGln !== '') {
                    $this->recordUnmatchedGln(
                        $document,
                        $unmatchedGln,
                        $glnUri,
                        $context,
                        $resolved['trading_partner_id'],
                        $resolved['site_id'],
                    );
                }
            }

            $extra = [
                'source_dest_type' => $party['source_dest_type'] ?? null,
            ];
            if (filled($party['type_uri'] ?? null)) {
                $extra['source_dest_type_uri'] = (string) $party['type_uri'];
            }

            $partyRows[] = [
                'event_id' => $eventId,
                'party_role' => $context,
                'gln' => $location['persisted_gln'],
                'gln_uri' => $glnUri,
                'trading_partner_id' => $resolved['trading_partner_id'] ?? null,
                'site_id' => $resolved['site_id'] ?? null,
                'extra_json' => json_encode($extra, JSON_THROW_ON_ERROR),
            ];
        }

        if ($partyRows !== []) {
            DB::table('event_parties')->insert($partyRows);
        }

        $this->recordPublishedPartnerSglns($locationRows, $partyRows);
    }

    /**
     * @param  list<array<string, mixed>>  $locationRows
     * @param  list<array<string, mixed>>  $partyRows
     */
    private function recordPublishedPartnerSglns(array $locationRows, array $partyRows): void
    {
        $seen = [];

        foreach ([...$locationRows, ...$partyRows] as $row) {
            $gln = $row['gln'] ?? null;
            $urn = $row['gln_uri'] ?? null;
            if (! is_string($gln) || $gln === '' || ! is_string($urn) || $urn === '') {
                continue;
            }
            $key = $gln."\0".$urn;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            app(RecordPublishedSglnOnPartner::class)->handle($gln, $urn);
        }
    }

    /**
     * @return array{
     *     name: string|null,
     *     street_address: string|null,
     *     city: string|null,
     *     state: string|null,
     *     postal_code: string|null,
     *     country_code: string|null
     * }
     */
    private function documentLocationOverlay(
        EpcisDocument $document,
        int $generation,
        ?string $gln,
        string $uri,
    ): array {
        $empty = [
            'name' => null,
            'street_address' => null,
            'city' => null,
            'state' => null,
            'postal_code' => null,
            'country_code' => null,
        ];

        $lookup = $this->cachedDocumentLocations($document, $generation);
        $row = null;
        if ($uri !== '' && isset($lookup['by_uri'][$uri])) {
            $row = $lookup['by_uri'][$uri];
        } elseif (filled($gln) && isset($lookup['by_gln'][(string) $gln])) {
            $row = $lookup['by_gln'][(string) $gln];
        }

        if ($row === null) {
            return $empty;
        }

        return [
            'name' => $row->name ?? null,
            'street_address' => $row->street_address ?? null,
            'city' => $row->city ?? null,
            'state' => $row->state ?? null,
            'postal_code' => $row->postal_code ?? null,
            'country_code' => $row->country_code ?? null,
        ];
    }

    /**
     * @return array{by_uri: array<string, object>, by_gln: array<string, object>}
     */
    private function cachedDocumentLocations(EpcisDocument $document, int $generation): array
    {
        $documentId = (int) $document->getKey();
        if (
            $this->documentLocationLookup !== null
            && $this->documentLocationLookupDocumentId === $documentId
            && $this->documentLocationLookupGeneration === $generation
        ) {
            return $this->documentLocationLookup;
        }

        $byUri = [];
        $byGln = [];

        if (Schema::hasTable('epcis_document_locations')) {
            $rows = DB::table('epcis_document_locations')
                ->where('document_id', $documentId)
                ->where('ingest_generation', $generation)
                ->select([
                    'gln_uri',
                    'gln',
                    'name',
                    'street_address',
                    'city',
                    'state',
                    'postal_code',
                    'country_code',
                ])
                ->get();

            foreach ($rows as $row) {
                $glnUri = (string) ($row->gln_uri ?? '');
                if ($glnUri !== '') {
                    $byUri[$glnUri] = $row;
                }
                $rowGln = filled($row->gln ?? null) ? (string) $row->gln : null;
                if ($rowGln !== null && ! isset($byGln[$rowGln])) {
                    $byGln[$rowGln] = $row;
                }
            }
        }

        $this->documentLocationLookup = [
            'by_uri' => $byUri,
            'by_gln' => $byGln,
        ];
        $this->documentLocationLookupDocumentId = $documentId;
        $this->documentLocationLookupGeneration = $generation;

        return $this->documentLocationLookup;
    }

    /**
     * @param  array<string, mixed>  $eventData
     */
    private function persistBizTransactions(int $eventId, array $eventData): void
    {
        $btRows = [];
        foreach ($eventData['biz_transactions'] ?? [] as $bt) {
            $btRows[] = [
                'event_id' => $eventId,
                'type_uri' => (string) ($bt['type_uri'] ?? 'unknown'),
                'value' => (string) ($bt['value'] ?? ''),
            ];
        }

        if ($btRows !== []) {
            DB::table('event_biz_transactions')->insert($btRows);
        }
    }

    /**
     * @param  array<string, mixed>  $eventData
     */
    private function persistQuantities(int $eventId, array $eventData): void
    {
        if (! Schema::hasTable('event_quantities')) {
            return;
        }

        // Prefer unmatched class-level quantities; fall back to quantities for older parsers.
        $source = $eventData['class_quantities'] ?? null;
        if (! is_array($source)) {
            $source = $eventData['quantities'] ?? [];
        }

        $rows = [];
        foreach ($source as $qty) {
            if (! is_array($qty)) {
                continue;
            }

            $epcClass = trim((string) ($qty['epc_class'] ?? ''));
            if ($epcClass === '') {
                continue;
            }

            $rows[] = [
                'event_id' => $eventId,
                'role' => Str::limit((string) ($qty['role'] ?? 'quantityList'), 32, ''),
                'epc_class' => Str::limit($epcClass, 191, ''),
                'quantity' => $qty['quantity'] ?? null,
                'uom' => filled($qty['uom'] ?? null) ? Str::limit((string) $qty['uom'], 32, '') : null,
            ];
        }

        if ($rows !== []) {
            DB::table('event_quantities')->insert($rows);
        }
    }

    /**
     * @param  array<string, mixed>  $eventData
     * @param  array<string, int>  $epcIdByUri
     */
    private function persistIlmd(EpcisDocument $document, int $eventId, array $eventData, array $epcIdByUri): void
    {
        $ilmd = $eventData['ilmd'] ?? null;
        if (! is_array($ilmd)) {
            return;
        }

        $lotNumber = $ilmd['lot_number'] ?? null;
        $expiryDate = $this->normalizeDate($ilmd['expiry_date'] ?? null);
        $manufacturingDate = $this->normalizeDate($ilmd['manufacturing_date'] ?? null);
        $bestBeforeDate = $this->normalizeDate($ilmd['best_before_date'] ?? null);
        $additionalId = filled($ilmd['additional_id'] ?? null)
            ? Str::limit((string) $ilmd['additional_id'], 64, '')
            : null;
        $extraJson = $ilmd['extra_json'] ?? null;
        $extraJsonEncoded = is_array($extraJson) && $extraJson !== []
            ? json_encode($extraJson, JSON_THROW_ON_ERROR)
            : null;

        $epcIlmdRows = [];
        $eventIlmdRows = [];
        $candidateEpcIds = [];

        foreach ($eventData['epcs'] ?? [] as $epcRef) {
            $role = (string) ($epcRef['role'] ?? '');
            if (! in_array($role, ['epcList', 'inputEPC', 'outputEPC'], true)) {
                continue;
            }
            $uri = (string) ($epcRef['uri'] ?? '');
            $epcId = $epcIdByUri[$uri] ?? null;
            if ($epcId === null) {
                continue;
            }

            $candidateEpcIds[] = $epcId;
        }

        $existingByEpcId = [];
        if ($candidateEpcIds !== [] && Schema::hasTable('epc_ilmd')) {
            $existingByEpcId = DB::table('epc_ilmd')
                ->whereIn('epc_id', array_values(array_unique($candidateEpcIds)))
                ->get()
                ->keyBy('epc_id')
                ->all();
        }

        foreach ($eventData['epcs'] ?? [] as $epcRef) {
            $role = (string) ($epcRef['role'] ?? '');
            if (! in_array($role, ['epcList', 'inputEPC', 'outputEPC'], true)) {
                continue;
            }
            $uri = (string) ($epcRef['uri'] ?? '');
            $epcId = $epcIdByUri[$uri] ?? null;
            if ($epcId === null) {
                continue;
            }

            $keptLot = $lotNumber;
            $keptExpiry = $expiryDate;
            $keptManufacturing = $manufacturingDate;
            $keptBestBefore = $bestBeforeDate;
            $keptAdditionalId = $additionalId;
            $keptExtraJson = $extraJsonEncoded;
            $existing = $existingByEpcId[$epcId] ?? null;

            if ($existing !== null) {
                $existingLot = $existing->lot_number;
                $existingExpiry = $this->normalizeDate($existing->expiry_date ?? null);
                $incomingLot = $lotNumber;
                $incomingExpiry = $expiryDate;

                $lotDiffers = $this->ilmdScalarDiffers($existingLot, $incomingLot);
                $expiryDiffers = $this->ilmdScalarDiffers($existingExpiry, $incomingExpiry);

                // First-wins fill: non-blank existing lot/expiry beats blank incoming (no wipe).
                $keptLot = $this->ilmdFirstWinsScalar($existingLot, $incomingLot);
                $keptExpiry = $this->ilmdFirstWinsScalar($existingExpiry, $incomingExpiry);

                if ($lotDiffers || $expiryDiffers) {
                    // Shared epc_ilmd is URI-scoped across documents: first-wins on lot/expiry
                    // so a later TI cannot clobber an earlier lot/expiry. Soft-signal the conflict.
                    // Also keep other non-blank shared ILMD fields; event_epc_ilmd still stores incoming.
                    $this->recordSharedIlmdLotMismatch(
                        $epcId,
                        $existingLot,
                        $existingExpiry,
                        $incomingLot,
                        $incomingExpiry,
                    );

                    $keptManufacturing = $this->ilmdFirstWinsScalar(
                        $this->normalizeDate($existing->manufacturing_date ?? null),
                        $manufacturingDate,
                    );
                    $keptBestBefore = $this->ilmdFirstWinsScalar(
                        $this->normalizeDate($existing->best_before_date ?? null),
                        $bestBeforeDate,
                    );
                    $keptAdditionalId = $this->ilmdFirstWinsScalar(
                        $existing->additional_id ?? null,
                        $additionalId,
                    );
                    $existingExtra = $existing->extra_json ?? null;
                    if (is_string($existingExtra) && $existingExtra !== '') {
                        $keptExtraJson = $existingExtra;
                    } elseif ($existingExtra !== null && $existingExtra !== '' && ! is_string($existingExtra)) {
                        $keptExtraJson = json_encode($existingExtra, JSON_THROW_ON_ERROR);
                    }
                }
            }

            $row = [
                'epc_id' => $epcId,
                'lot_number' => $keptLot,
                'expiry_date' => $keptExpiry,
                'manufacturing_date' => $keptManufacturing,
                'best_before_date' => $keptBestBefore,
                'additional_id' => $keptAdditionalId,
                'extra_json' => $keptExtraJson,
            ];
            if (Schema::hasColumn('epc_ilmd', 'gtin14')) {
                $row['gtin14'] = $this->gtinByEpcId[$epcId] ?? null;
            }

            $epcIlmdRows[] = $row;

            if (Schema::hasTable('event_epc_ilmd')) {
                $eventIlmdRows[] = [
                    'event_id' => $eventId,
                    'epc_id' => $epcId,
                    // Event-scoped ILMD always records this document's values.
                    'lot_number' => $lotNumber,
                    'expiry_date' => $expiryDate,
                    'manufacturing_date' => $manufacturingDate,
                    'best_before_date' => $bestBeforeDate,
                    'additional_id' => $additionalId,
                    'extra_json' => $extraJsonEncoded,
                ];
            }
        }

        $updateCols = [
            'lot_number',
            'expiry_date',
            'manufacturing_date',
            'best_before_date',
            'additional_id',
            'extra_json',
        ];
        if (Schema::hasColumn('epc_ilmd', 'gtin14')) {
            $updateCols[] = 'gtin14';
        }

        foreach (array_chunk($epcIlmdRows, self::EPC_UPSERT_CHUNK) as $chunk) {
            DB::table('epc_ilmd')->upsert($chunk, ['epc_id'], $updateCols);
        }

        foreach (array_chunk($eventIlmdRows, self::EPC_UPSERT_CHUNK) as $chunk) {
            DB::table('event_epc_ilmd')->upsert(
                $chunk,
                ['event_id', 'epc_id'],
                [
                    'lot_number',
                    'expiry_date',
                    'manufacturing_date',
                    'best_before_date',
                    'additional_id',
                    'extra_json',
                ],
            );
        }
    }

    private function ilmdScalarDiffers(mixed $existing, mixed $incoming): bool
    {
        $left = $existing === null || $existing === '' ? null : (string) $existing;
        $right = $incoming === null || $incoming === '' ? null : (string) $incoming;

        if ($left === null || $right === null) {
            // Filling a blank or leaving a blank alone is not a conflict.
            return false;
        }

        return $left !== $right;
    }

    /**
     * First-wins fill: keep non-blank existing; only take incoming when existing is blank.
     */
    private function ilmdFirstWinsScalar(mixed $existing, mixed $incoming): mixed
    {
        $left = $existing === null || $existing === '' ? null : $existing;

        if ($left !== null) {
            return $left;
        }

        return $incoming === null || $incoming === '' ? null : $incoming;
    }

    private function recordSharedIlmdLotMismatch(
        int $epcId,
        mixed $existingLot,
        mixed $existingExpiry,
        mixed $incomingLot,
        mixed $incomingExpiry,
    ): void {
        if (isset($this->ilmdLotMismatchSignaledEpcIds[$epcId])) {
            return;
        }

        $this->ilmdLotMismatchSignaledEpcIds[$epcId] = true;
        $this->pendingSharedIlmdLotMismatches[] = [
            'epc_id' => $epcId,
            'existing_lot' => $existingLot,
            'existing_expiry' => $existingExpiry,
            'incoming_lot' => $incomingLot,
            'incoming_expiry' => $incomingExpiry,
        ];
    }

    private function flushSharedIlmdLotMismatchExceptions(EpcisDocument $document): void
    {
        // Own rewrite path only — never wipe operational/hook LOT_MISMATCH rows.
        $this->clearSharedIlmdLotMismatchExceptions($document);

        if ($this->pendingSharedIlmdLotMismatches === []) {
            return;
        }

        foreach ($this->pendingSharedIlmdLotMismatches as $conflict) {
            $epcId = $conflict['epc_id'];

            EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'epc_id' => $epcId,
                'exception_type' => 'LOT_MISMATCH',
                'severity' => 'warning',
                'description' => Str::limit(
                    self::SHARED_ILMD_LOT_MISMATCH_DESCRIPTION_PREFIX.': kept first-wins values '
                    .'(lot='.($conflict['existing_lot'] ?? 'null')
                    .', expiry='.($conflict['existing_expiry'] ?? 'null').'); '
                    .'incoming document had lot='.($conflict['incoming_lot'] ?? 'null')
                    .', expiry='.($conflict['incoming_expiry'] ?? 'null').'.',
                    2000,
                ),
                'status' => 'open',
            ]);
        }

        $this->pendingSharedIlmdLotMismatches = [];
    }

    private function clearSharedIlmdLotMismatchExceptions(EpcisDocument $document): void
    {
        EpcisException::query()
            ->where('document_id', $document->getKey())
            ->where('exception_type', 'LOT_MISMATCH')
            ->where('status', 'open')
            ->where('description', 'like', self::SHARED_ILMD_LOT_MISMATCH_DESCRIPTION_PREFIX.'%')
            ->delete();
    }

    /**
     * @param  array<int, true>  $documentEpcIds
     */
    private function syncDocumentEpcs(EpcisDocument $document, int $generation, array $documentEpcIds): void
    {
        if (! Schema::hasTable('document_epcs') || $documentEpcIds === []) {
            return;
        }

        $rows = [];
        foreach (array_keys($documentEpcIds) as $epcId) {
            $rows[] = [
                'document_id' => $document->getKey(),
                'epc_id' => $epcId,
                'ingest_generation' => $generation,
            ];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('document_epcs')->insertOrIgnore($chunk);
        }
    }

    /**
     * @param  list<array{idpat?: string, ndc11?: string|null, name?: string|null, placeholder_fields?: list<string>}>  $productClasses
     * @param  list<string>  $documentEpcUris
     */
    private function recordComplianceExceptions(
        EpcisDocument $document,
        array $productClasses = [],
        array $documentEpcUris = [],
    ): void {
        // DSCSA TS affirmation is enforced as MISSING_DSCSA_STATEMENT by ValidateEpcis12Document.
        // MISSING_BIZ_TRANSACTION is emitted by EpcisCatalogBusinessRules.

        $this->recordIncompleteProductMasterDataExceptions($document, $productClasses, $documentEpcUris);
    }

    /**
     * Delete open soft-signal / legacy exceptions for this document before re-deriving
     * them, so reprocess refreshes the signals instead of getting stuck by early-return
     * "already open" checks in the downstream writers.
     */
    private function clearSoftSignalExceptions(EpcisDocument $document): void
    {
        EpcisException::query()
            ->where('document_id', $document->getKey())
            ->whereIn('exception_type', [
                'ingest_failure',
                'INGESTION_PARSE_ERROR',
                'missing_transaction_statement',
                'missing_biz_transaction',
                'MISSING_BIZ_TRANSACTION',
                'dropped_epc_uris',
                'INVALID_EPC_URI',
                'incomplete_product_master_data',
                'MASTER_DATA_SYNC_LAG',
                'atp_soft_warning',
                RecordSbdhOwningPartyMismatch::EXCEPTION_TYPE,
                RecordDestinationGlnMismatch::OWNING_PARTY_EXCEPTION_TYPE,
                RecordDestinationGlnMismatch::LOCATION_EXCEPTION_TYPE,
                RecordScheduledProductMissingDea::EXCEPTION_TYPE,
            ])
            ->where('status', 'open')
            ->delete();
    }

    private function recordDroppedEpcUriExceptions(EpcisDocument $document): void
    {
        if ($this->droppedEpcUris === []) {
            return;
        }

        $sample = implode(', ', array_slice(array_unique($this->droppedEpcUris), 0, 10));
        EpcisException::query()->create([
            'document_id' => $document->getKey(),
            'exception_type' => 'INVALID_EPC_URI',
            'severity' => 'warning',
            'description' => Str::limit(
                'Unparseable EPC URIs skipped ('.count(array_unique($this->droppedEpcUris)).'): '.$sample,
                2000,
            ),
            'status' => 'open',
        ]);
    }

    /**
     * Flag EPCIS master-data vocabulary rows that use placeholder values (e.g. regulatedProductName=N/A)
     * when that GTIN pattern is actually present in the document.
     *
     * @param  list<array{idpat?: string, ndc11?: string|null, name?: string|null, placeholder_fields?: list<string>}>  $productClasses
     * @param  list<string>  $documentEpcUris
     */
    private function recordIncompleteProductMasterDataExceptions(
        EpcisDocument $document,
        array $productClasses,
        array $documentEpcUris,
    ): void {
        if ($productClasses === [] || $documentEpcUris === []) {
            return;
        }

        $issues = [];
        foreach ($productClasses as $class) {
            $idpat = (string) ($class['idpat'] ?? '');
            $placeholders = array_values(array_unique($class['placeholder_fields'] ?? []));
            if ($idpat === '' || $placeholders === []) {
                continue;
            }

            if (! $this->idpatUsedInDocument($idpat, $documentEpcUris)) {
                continue;
            }

            $issues[] = $idpat.' ('.implode(', ', $placeholders).')';
        }

        if ($issues === []) {
            return;
        }

        $exists = EpcisException::query()
            ->where('document_id', $document->getKey())
            ->whereIn('exception_type', ['incomplete_product_master_data', 'MASTER_DATA_SYNC_LAG'])
            ->where('status', 'open')
            ->exists();

        if ($exists) {
            return;
        }

        EpcisException::query()->create([
            'document_id' => $document->getKey(),
            'exception_type' => 'MASTER_DATA_SYNC_LAG',
            'severity' => 'warning',
            'description' => Str::limit(
                'EPCIS product master data contains placeholder values (N/A) for: '.implode('; ', $issues),
                2000,
            ),
            'status' => 'open',
        ]);
    }

    /**
     * @param  list<string>  $documentEpcUris
     */
    private function idpatUsedInDocument(string $idpat, array $documentEpcUris): bool
    {
        if (preg_match('/^urn:epc:idpat:sgtin:(\d+)\.(\d+)\.\*$/', $idpat, $matches) !== 1) {
            return false;
        }

        $prefix = 'urn:epc:id:sgtin:'.$matches[1].'.'.$matches[2].'.';

        foreach ($documentEpcUris as $uri) {
            if (str_starts_with((string) $uri, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *     gln: string,
     *     trading_partner_id: ?int,
     *     site_id: ?int,
     *     location_device_id: ?int,
     *     read_point_id: ?int,
     *     trading_partner: mixed,
     *     site: mixed,
     *     location_device: mixed,
     *     read_point: mixed
     * }
     */
    private function cachedResolveGln(string $token): array
    {
        $cacheKey = $this->resolveGlnCacheKey($token);
        if (isset($this->glnCache[$cacheKey])) {
            return $this->glnCache[$cacheKey];
        }

        return $this->glnCache[$cacheKey] = $this->resolveGln->handle($token);
    }

    private function resolveGlnCacheKey(string $token): string
    {
        $gln = Sgln::normalizeGln($token);
        if ($gln !== null) {
            return 'gln:'.$gln;
        }

        $dea = DeaRegistration::parseFromLocationToken($token);
        if ($dea !== null) {
            return 'dea:'.$dea;
        }

        return 'token:'.strtoupper(trim($token));
    }

    /**
     * @return array{
     *     parsed_gln: ?string,
     *     persisted_gln: ?string,
     *     overlay_gln: ?string,
     *     resolved: array{
     *         gln: string,
     *         trading_partner_id: ?int,
     *         site_id: ?int,
     *         location_device_id: ?int,
     *         read_point_id: ?int,
     *         trading_partner: mixed,
     *         site: mixed,
     *         location_device: mixed,
     *         read_point: mixed
     *     },
     *     resolve_token: string
     * }
     */
    private function resolveEpcisLocationToken(string $uri): array
    {
        $sgln = Sgln::fromUrn($uri);
        $parsedGln = $sgln['gln'] ?? null;
        $resolveToken = $parsedGln ?? $uri;
        $resolved = $this->cachedResolveGln($resolveToken);
        $persistedGln = filled($resolved['gln']) ? (string) $resolved['gln'] : null;

        return [
            'parsed_gln' => $parsedGln,
            'persisted_gln' => $persistedGln,
            'overlay_gln' => $parsedGln ?? $persistedGln,
            'resolved' => $resolved,
            'resolve_token' => $resolveToken,
        ];
    }

    /**
     * @param  array{
     *     trading_partner_id: ?int,
     *     site_id: ?int,
     *     location_device_id: ?int,
     *     read_point_id: ?int
     * }  $resolved
     */
    private function hasAnyMasterData(array $resolved): bool
    {
        return $resolved['trading_partner_id'] !== null
            || $resolved['site_id'] !== null
            || $resolved['location_device_id'] !== null
            || $resolved['read_point_id'] !== null;
    }

    /**
     * @param  array{
     *     gln?: string,
     *     trading_partner_id: ?int,
     *     site_id: ?int,
     *     location_device_id: ?int,
     *     read_point_id: ?int
     * }  $resolved
     */
    private function isKnownParty(array $resolved): bool
    {
        if ($this->hasAnyMasterData($resolved)) {
            return true;
        }

        $tenantGln = preg_replace('/\D+/', '', (string) (tenant()?->gln ?? '')) ?? '';
        $resolvedGln = preg_replace('/\D+/', '', (string) ($resolved['gln'] ?? '')) ?? '';

        return strlen($tenantGln) === 13
            && strlen($resolvedGln) === 13
            && hash_equals($tenantGln, $resolvedGln);
    }

    private function recordUnmatchedGln(
        EpcisDocument $document,
        string $gln,
        ?string $glnUri,
        string $context,
        ?int $tradingPartnerId,
        ?int $siteId,
    ): void {
        if (! Schema::hasTable('epcis_unmatched_glns')) {
            return;
        }

        $normalized = preg_replace('/\D+/', '', $gln) ?? '';
        if (strlen($normalized) !== 13) {
            return;
        }

        EpcisUnmatchedGln::query()->updateOrCreate(
            [
                'document_id' => $document->getKey(),
                'gln' => $normalized,
                'context' => $context,
            ],
            [
                'gln_uri' => $glnUri,
                'trading_partner_id' => $tradingPartnerId,
                'site_id' => $siteId,
            ],
        );
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
