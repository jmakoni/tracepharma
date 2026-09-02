<?php

namespace App\Models\Epcis;

use App\Enums\EpcisAuthoredKind;
use App\Enums\EpcisReceivedVia;
use App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource;
use App\Filament\App\Resources\OutboundEpcisDocuments\OutboundEpcisDocumentResource;
use App\Models\Concerns\TenantSearchable;
use App\Models\Fda\FdaProductPackaging;
use App\Models\OutboundConnection;
use App\Models\Product;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\SsccLabelBatch;
use App\Models\TradingPartner;
use App\Models\Transferring\TransferringSession;
use App\Services\Receiving\ReceivingGate;
use App\Support\Epcis\EpcisXmlReader;
use App\Support\Gs1\Ndc;
use App\Support\Gs1\Sgtin;
use App\Support\Shipping\CorrectiveShipmentDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class EpcisDocument extends Model
{
    use LogsActivity;
    use TenantSearchable;

    protected $table = 'epcis_documents';

    protected $fillable = [
        'document_uuid',
        'ingest_generation',
        'document_uuid_synthesized',
        'schema_version',
        'creation_date',
        'direction',
        'authored_kind',
        'corrects_epcis_document_id',
        'trading_partner_id',
        'sender_gln',
        'receiver_gln',
        'customer_po',
        'asn_number',
        'ship_from_gln',
        'ship_to_gln',
        'ship_from_name',
        'ship_from_site_name',
        'ship_to_name',
        'ship_to_site_name',
        'ship_from_site_id',
        'ship_to_site_id',
        'ship_to_partner_id',
        'inbound_connection_id',
        'received_via',
        'outbound_connection_id',
        'format',
        'original_filename',
        'file_sha256',
        'payload_disk',
        'payload_path',
        'dscsa_affirm',
        'legal_notice',
        'direct_purchase_qualifier',
        'direct_purchase_statement',
        'direct_purchase_indirect_epc_uris',
        'received_prev_wholesaler_qualifier',
        'received_prev_wholesaler_statement',
        'received_prev_wholesaler_indirect_epc_uris',
        'header_json',
        'status',
        'error_message',
        'notes',
        'reprocess_count',
        'event_count',
        'epc_count',
        'received_at',
        'sent_at',
        'transmission_status',
        'l3_forwarded_at',
        'processed_at',
        'last_processed_at',
    ];

    protected function casts(): array
    {
        return [
            'creation_date' => 'datetime',
            'authored_kind' => EpcisAuthoredKind::class,
            'received_via' => EpcisReceivedVia::class,
            'ingest_generation' => 'integer',
            'document_uuid_synthesized' => 'boolean',
            'dscsa_affirm' => 'boolean',
            'direct_purchase_indirect_epc_uris' => 'array',
            'received_prev_wholesaler_indirect_epc_uris' => 'array',
            'header_json' => 'array',
            'reprocess_count' => 'integer',
            'event_count' => 'integer',
            'epc_count' => 'integer',
            'received_at' => 'datetime',
            'sent_at' => 'datetime',
            'l3_forwarded_at' => 'datetime',
            'processed_at' => 'datetime',
            'last_processed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'reprocess_count', 'ingest_generation', 'dscsa_affirm', 'error_message'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            ...$this->tenantSearchMetadata(),
            'document_uuid' => $this->document_uuid,
            'original_filename' => $this->original_filename,
            'status' => $this->status,
            'direction' => $this->direction,
            'asn_number' => $this->asn_number,
            'customer_po' => $this->customer_po,
            'sender_gln' => $this->sender_gln,
            'receiver_gln' => $this->receiver_gln,
            'ship_from_gln' => $this->ship_from_gln,
            'ship_to_gln' => $this->ship_to_gln,
            'trading_partner_name' => $this->tradingPartner?->name,
        ];
    }

    /**
     * Partner-facing Inbound EPCIS catalog: Upload EPCIS + hub receives only.
     *
     * @param  Builder<EpcisDocument>  $query
     * @return Builder<EpcisDocument>
     */
    public function scopeInboundCatalog(Builder $query): Builder
    {
        return $query
            ->where('direction', 'inbound')
            ->whereIn('received_via', EpcisReceivedVia::catalogValues());
    }

    /**
     * UI label for direction. Authored docs use direction=outbound
     * (we wrote the file) but must not read as a DSCSA shipment.
     *
     * Prefers the persisted authored_kind; for outbound documents authored
     * before that column existed, falls back to the legacy notes/filename
     * heuristics used to backfill it.
     */
    public function directionDisplayLabel(): string
    {
        if ($this->direction === 'outbound') {
            $authoredKind = $this->authored_kind;

            if (! $authoredKind instanceof EpcisAuthoredKind) {
                $authoredKind = EpcisAuthoredKind::inferAuthoredKindFromNotesAndFilename(
                    (string) $this->notes,
                    (string) $this->original_filename,
                );
            }

            if ($authoredKind instanceof EpcisAuthoredKind) {
                return $authoredKind->displayLabel();
            }
        }

        return match ((string) $this->direction) {
            'inbound' => 'Inbound',
            'outbound' => 'Outbound',
            default => (string) ($this->direction ?: '—'),
        };
    }

    /**
     * Filament "view" URL for this document, routed to the outbound or
     * inbound EPCIS resource based on {@see $direction}.
     */
    public function filamentViewUrl(?string $panel = 'app'): string
    {
        if ($this->direction === 'outbound') {
            return OutboundEpcisDocumentResource::getUrl(
                'view',
                ['record' => $this],
                panel: $panel,
            );
        }

        return EpcisDocumentResource::getUrl(
            'view',
            ['record' => $this],
            panel: $panel,
        );
    }

    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class);
    }

    public function outboundConnection(): BelongsTo
    {
        return $this->belongsTo(OutboundConnection::class);
    }

    /**
     * The authored shipment this one corrects, when it is a corrective shipment.
     */
    public function correctsDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, CorrectiveShipmentDocument::COLUMN);
    }

    public function shipFromSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'ship_from_site_id');
    }

    public function shipToSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'ship_to_site_id');
    }

    public function shipToPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class, 'ship_to_partner_id');
    }

    public function receivingSession(): HasOne
    {
        return $this->hasOne(ReceivingSession::class, 'epcis_document_id');
    }

    /**
     * Floor receive overlay for inbound list/view badges.
     * null = show ingest pipeline status instead.
     *
     * Priority: Received / Partially Received → Receive Blocked → ingest badge.
     */
    public function floorReceiveStatusLabel(): ?string
    {
        $session = $this->receivingSession;

        if ($session !== null && $session->status !== 'cancelled') {
            $expectedParent = (int) ($session->expected_parent_count ?? 0);
            $confirmedParent = (int) ($session->confirmed_parent_count ?? 0);
            $expectedChild = (int) ($session->expected_child_count ?? 0);
            $confirmedChild = (int) ($session->confirmed_child_count ?? 0);

            if ($session->status === 'completed') {
                return 'Received';
            }

            $parentsDone = $expectedParent === 0 || $confirmedParent >= $expectedParent;
            $childrenDone = $expectedChild === 0 || $confirmedChild >= $expectedChild;
            $hasActivity = $expectedParent > 0
                || $expectedChild > 0
                || $confirmedParent > 0
                || $confirmedChild > 0;

            if ($parentsDone && $childrenDone && $hasActivity) {
                return 'Received';
            }

            $confirmedTotal = $confirmedParent + $confirmedChild;
            if ($confirmedTotal > 0 || $session->status === 'in_progress') {
                return 'Partially Received';
            }
        }

        if (app(ReceivingGate::class)->documentBlockedByOpenException($this) !== null) {
            return 'Receive Blocked';
        }

        return null;
    }

    public function isFloorReceived(): bool
    {
        return $this->floorReceiveStatusLabel() === 'Received';
    }

    /**
     * Linked session that can still accept scans (not completed/cancelled).
     */
    public function openReceivingSession(): ?ReceivingSession
    {
        $session = $this->receivingSession;

        if ($session === null || ! in_array($session->status, ['open', 'in_progress'], true)) {
            return null;
        }

        return $session;
    }

    /**
     * Badge color for {@see floorReceiveStatusLabel()}; null falls back to ingest status styling.
     */
    public function floorReceiveStatusColor(): ?string
    {
        return match ($this->floorReceiveStatusLabel()) {
            'Received' => 'success',
            'Partially Received' => 'warning',
            'Receive Blocked' => 'danger',
            default => null,
        };
    }

    public function outboundShippingSession(): HasOne
    {
        return $this->hasOne(OutboundShippingSession::class, 'epcis_document_id');
    }

    public function transferringSession(): HasOne
    {
        return $this->hasOne(TransferringSession::class, 'transfer_epcis_document_id');
    }

    /**
     * Resolve the SSCC label batch this authored document belongs to (not a
     * standard Eloquent relation — looked up by payload path, falling back
     * to the legacy `sscc_label_batch_id=` marker in notes).
     */
    public function ssccLabelBatch(): ?SsccLabelBatch
    {
        $path = $this->payload_path;
        if (filled($path)) {
            $batch = SsccLabelBatch::query()
                ->where(function (Builder $query) use ($path): void {
                    $query->where('commissioning_epcis_file_path', $path)
                        ->orWhere('epcis_file_path', $path)
                        ->orWhere('disaggregation_file_path', $path);
                })
                ->first();

            if ($batch !== null) {
                return $batch;
            }
        }

        if (preg_match('/sscc_label_batch_id=(\d+)/', (string) $this->notes, $matches) === 1) {
            return SsccLabelBatch::query()->find((int) $matches[1]);
        }

        return null;
    }

    /**
     * Whether this document is an SSCC commissioning/aggregation/disaggregation
     * authored kind. Prefers persisted authored_kind; falls back to notes/filename
     * inference for pre-backfill rows (same path as directionDisplayLabel()).
     */
    public function isSsccAuthoredKind(): bool
    {
        $kind = $this->authored_kind;

        if (! $kind instanceof EpcisAuthoredKind && $this->direction === 'outbound') {
            $kind = EpcisAuthoredKind::inferAuthoredKindFromNotesAndFilename(
                (string) $this->notes,
                (string) $this->original_filename,
            );
        }

        return in_array($kind, [
            EpcisAuthoredKind::SsccCommissioning,
            EpcisAuthoredKind::SsccAggregation,
            EpcisAuthoredKind::SsccDisaggregation,
        ], true);
    }

    public function events(): HasMany
    {
        return $this->hasMany(EpcisEvent::class, 'document_id');
    }

    /**
     * Events belonging to this document's current ingest generation.
     */
    public function activeEvents(): HasMany
    {
        $relation = $this->hasMany(EpcisEvent::class, 'document_id')
            ->where('ingest_generation', $this->ingest_generation ?? 1);

        if (Schema::hasColumn('epcis_events', 'superseded_at')) {
            $relation->whereNull('epcis_events.superseded_at');
        }

        return $relation;
    }

    public function documentEpcs(): HasMany
    {
        return $this->hasMany(DocumentEpc::class, 'document_id');
    }

    public function eventEpcs(): HasManyThrough
    {
        return $this->hasManyThrough(
            EventEpc::class,
            EpcisEvent::class,
            'document_id',
            'event_id',
            'id',
            'id',
        );
    }

    /**
     * Distinct EPCs referenced by this document's active generation (not a standard Eloquent relation).
     */
    public function epcsQuery(): Builder
    {
        $documentId = $this->getKey();
        $generation = (int) ($this->ingest_generation ?? 1);

        if (Schema::hasTable('document_epcs')) {
            return Epc::query()
                ->whereIn('epcs.id', function ($query) use ($documentId, $generation): void {
                    $query->select('document_epcs.epc_id')
                        ->from('document_epcs')
                        ->where('document_epcs.document_id', $documentId)
                        ->where('document_epcs.ingest_generation', $generation);
                });
        }

        return Epc::query()
            ->whereIn('epcs.id', function ($query) use ($documentId, $generation): void {
                $query->select('event_epcs.epc_id')
                    ->from('event_epcs')
                    ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
                    ->where('epcis_events.document_id', $documentId);

                if (Schema::hasColumn('epcis_events', 'ingest_generation')) {
                    $query->where('epcis_events.ingest_generation', $generation);
                }
            });
    }

    /**
     * Distinct EPCs for this document's active generation plus one hop of open aggregation children.
     *
     * Base set prefers document_epcs for the active generation; when that set is empty, falls back
     * to event_epcs for active-generation events. Open aggregation children (valid_to IS NULL) whose
     * parent is in the base set are included — enough for pallet→case/unit when cases are listed, or
     * case→unit when the case is a document EPC.
     */
    public function epcsQueryExpanded(): Builder
    {
        $baseEpcIds = $this->baseEpcIdsQuery();

        if (! Schema::hasTable('aggregation_links')) {
            return Epc::query()->whereIn('epcs.id', $baseEpcIds);
        }

        $childEpcIds = DB::table('aggregation_links')
            ->select('aggregation_links.child_epc_id as epc_id')
            ->whereNull('aggregation_links.valid_to')
            ->whereIn('aggregation_links.parent_epc_id', $baseEpcIds);

        $expandedEpcIds = $baseEpcIds->union($childEpcIds);

        return Epc::query()->whereIn('epcs.id', $expandedEpcIds);
    }

    /**
     * Count of distinct EPCs returned by {@see epcsQueryExpanded()} (for UI badges).
     */
    public function epcsExpandedCount(): int
    {
        return (int) $this->epcsQueryExpanded()->count();
    }

    /**
     * Subquery of distinct base EPC ids for the active generation (document_epcs, else event_epcs).
     */
    public function baseEpcIdsQuery(): \Illuminate\Database\Query\Builder
    {
        $documentId = (int) $this->getKey();
        $generation = (int) ($this->ingest_generation ?? 1);

        if ($this->usesDocumentEpcsForGeneration($documentId, $generation)) {
            return DB::table('document_epcs')
                ->select('document_epcs.epc_id')
                ->where('document_epcs.document_id', $documentId)
                ->where('document_epcs.ingest_generation', $generation);
        }

        $query = DB::table('event_epcs')
            ->select('event_epcs.epc_id')
            ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
            ->where('epcis_events.document_id', $documentId);

        if (Schema::hasColumn('epcis_events', 'ingest_generation')) {
            $query->where('epcis_events.ingest_generation', $generation);
        }

        return $query;
    }

    private function usesDocumentEpcsForGeneration(int $documentId, int $generation): bool
    {
        if (! Schema::hasTable('document_epcs')) {
            return false;
        }

        return DB::table('document_epcs')
            ->where('document_id', $documentId)
            ->where('ingest_generation', $generation)
            ->exists();
    }

    /**
     * Distinct tenant products linked from this document's active-generation EPCs.
     *
     * @return Builder<Product>
     */
    public function productsQuery(): Builder
    {
        $documentId = $this->getKey();
        $generation = (int) ($this->ingest_generation ?? 1);

        return Product::query()
            ->whereIn('products.id', function ($query) use ($documentId, $generation): void {
                if (Schema::hasTable('document_epcs')) {
                    $query->select('epcs.product_id')
                        ->from('document_epcs')
                        ->join('epcs', 'epcs.id', '=', 'document_epcs.epc_id')
                        ->where('document_epcs.document_id', $documentId)
                        ->where('document_epcs.ingest_generation', $generation)
                        ->whereNotNull('epcs.product_id')
                        ->distinct();

                    return;
                }

                $query->select('epcs.product_id')
                    ->from('event_epcs')
                    ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
                    ->join('epcs', 'epcs.id', '=', 'event_epcs.epc_id')
                    ->where('epcis_events.document_id', $documentId)
                    ->whereNotNull('epcs.product_id')
                    ->distinct();

                if (Schema::hasColumn('epcis_events', 'ingest_generation')) {
                    $query->where('epcis_events.ingest_generation', $generation);
                }
            })
            ->orderBy('products.name');
    }

    /**
     * Products found in this file, grouped by NDC (case+unit GTINs collapsed).
     *
     * @return Collection<int, array{
     *     key: string,
     *     gtin: string,
     *     name: string,
     *     ndc: ?string,
     *     dosage_form: ?string,
     *     strength: ?string,
     *     manufacturer: ?string,
     *     net_content: ?string,
     *     document_epc_count: int,
     *     case_count: int,
     *     unit_count: int,
     *     epc_breakdown: string,
     *     product_id: ?int,
     *     linked: bool,
     *     catalog_status: 'assortment'|'fda'|'none'
     * }>
     */
    public function fileProductSummaries(): Collection
    {
        $documentId = (int) $this->getKey();
        $generation = (int) ($this->ingest_generation ?? 1);
        $fileByGtin = $this->fileProductClassesByGtin();
        $gtinStats = $this->sgtinGtinStatsForGeneration($documentId, $generation);

        if ($gtinStats->isEmpty()) {
            return collect();
        }

        $gtins = $gtinStats->keys()->all();
        $productsByGtin = Product::query()
            ->with('tradingPartner')
            ->whereIn('gtin', $gtins)
            ->get()
            ->keyBy('gtin');

        $productIds = $gtinStats->pluck('product_id')->filter()->unique()->values()->all();
        $productsById = $productIds === []
            ? collect()
            : Product::query()
                ->with('tradingPartner')
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

        $ndc11s = $fileByGtin->pluck('ndc11')->filter()->unique()->values()->all();
        $productsByNdc11 = $ndc11s === []
            ? collect()
            : Product::query()
                ->with('tradingPartner')
                ->whereIn('ndc11', $ndc11s)
                ->get()
                ->keyBy('ndc11');

        $fdaPackagesByNdc11 = $this->fdaPackagesByNdc11($ndc11s);

        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];

        foreach ($gtinStats as $gtin => $stats) {
            $file = $fileByGtin->get($gtin);
            $ndc11 = filled($file['ndc11'] ?? null) ? (string) $file['ndc11'] : null;
            $groupKey = $ndc11 ?? 'gtin:'.$gtin;

            if (! isset($groups[$groupKey])) {
                $product = null;
                if (filled($stats['product_id'])) {
                    $product = $productsById->get((int) $stats['product_id']);
                }
                $product ??= $productsByGtin->get($gtin);
                if ($product === null && $ndc11 !== null) {
                    $product = $productsByNdc11->get($ndc11);
                }

                $fdaPackage = $ndc11 !== null ? $fdaPackagesByNdc11->get($ndc11) : null;

                // Display columns come from EPCIS XML vocabulary.
                // Name falls back to FDA brand/generic only when the file has no name.
                // Master-data badge uses FDA catalog first, then tenant assortment.
                $sourceNdc = filled($file['ndc_raw'] ?? null)
                    ? (string) $file['ndc_raw']
                    : $ndc11;

                $fileName = filled($file['name'] ?? null) ? (string) $file['name'] : null;
                $fdaProductName = null;
                if ($fileName === null && $fdaPackage !== null) {
                    $fdaProduct = $fdaPackage->relationLoaded('product')
                        ? $fdaPackage->product
                        : $fdaPackage->product()->first(['id', 'brand_name', 'generic_name']);
                    if ($fdaProduct !== null) {
                        $fdaProductName = filled($fdaProduct->brand_name)
                            ? (string) $fdaProduct->brand_name
                            : (filled($fdaProduct->generic_name) ? (string) $fdaProduct->generic_name : null);
                    }
                }

                $groups[$groupKey] = [
                    'key' => $groupKey,
                    'gtins' => [],
                    'name' => $fileName ?? ($fdaProductName ?? 'Unknown product'),
                    'ndc' => Ndc::formatPackageDisplay($sourceNdc),
                    'dosage_form' => $file['dosage_form'] ?? null,
                    'strength' => $file['strength'] ?? null,
                    'manufacturer' => $file['manufacturer'] ?? null,
                    'net_content' => $file['net_content'] ?? null,
                    'document_epc_count' => 0,
                    'case_count' => 0,
                    'unit_count' => 0,
                    'product_id' => $product?->getKey() !== null ? (int) $product->getKey() : null,
                    'linked' => $product !== null,
                    // Master-data badge: FDA catalog first, then tenant assortment.
                    'catalog_status' => $fdaPackage !== null
                        ? 'fda'
                        : ($product !== null ? 'assortment' : 'none'),
                ];
            }

            $groups[$groupKey]['gtins'][] = (string) $gtin;
            $groups[$groupKey]['document_epc_count'] += (int) $stats['total'];
            $groups[$groupKey]['case_count'] += (int) $stats['cases'];
            $groups[$groupKey]['unit_count'] += (int) $stats['units'];

            if ($groups[$groupKey]['product_id'] === null && filled($stats['product_id'])) {
                $product = $productsById->get((int) $stats['product_id']) ?? $productsByGtin->get($gtin);
                if ($product !== null) {
                    $groups[$groupKey]['product_id'] = (int) $product->getKey();
                    $groups[$groupKey]['linked'] = true;
                    if ($groups[$groupKey]['catalog_status'] === 'none') {
                        $groups[$groupKey]['catalog_status'] = 'assortment';
                    }
                }
            }

            if (($groups[$groupKey]['name'] === 'Unknown product') && filled($file['name'] ?? null)) {
                $groups[$groupKey]['name'] = (string) $file['name'];
            }
            foreach (['dosage_form', 'strength', 'manufacturer', 'net_content'] as $field) {
                if (($groups[$groupKey][$field] ?? null) === null && filled($file[$field] ?? null)) {
                    $groups[$groupKey][$field] = $file[$field];
                }
            }
        }

        return collect($groups)
            ->map(function (array $group): array {
                $cases = (int) $group['case_count'];
                $units = (int) $group['unit_count'];
                $total = (int) $group['document_epc_count'];
                if ($cases === 0 && $units === 0) {
                    $units = $total;
                }

                $gtins = array_values(array_unique($group['gtins']));
                sort($gtins);

                return [
                    'key' => (string) $group['key'],
                    'gtin' => implode(', ', $gtins),
                    'name' => (string) $group['name'],
                    'ndc' => $group['ndc'],
                    'dosage_form' => $group['dosage_form'],
                    'strength' => $group['strength'],
                    'manufacturer' => $group['manufacturer'],
                    'net_content' => $group['net_content'],
                    'document_epc_count' => $total,
                    'case_count' => $cases,
                    'unit_count' => $units,
                    'epc_breakdown' => $this->formatEpcBreakdown($cases, $units),
                    'product_id' => $group['product_id'],
                    'linked' => (bool) $group['linked'],
                    'catalog_status' => (string) $group['catalog_status'],
                ];
            })
            ->sortBy('name')
            ->values();
    }

    /**
     * Operational shipment summary for the Summary tab (products / lots / items / PO / ASN).
     *
     * @return array{
     *     product_count: int,
     *     product_ndcs: list<string>,
     *     lot_count: int,
     *     lots: list<string>,
     *     item_count: int,
     *     case_count: int,
     *     unit_count: int,
     *     case_unit_label: string,
     *     asn_number: ?string,
     *     customer_po: ?string,
     *     legal_notice: ?string,
     *     dscsa_affirm: bool
     * }
     */
    public function fileShipmentSummary(): array
    {
        $products = $this->fileProductSummaries();
        $lots = $this->distinctLotsForCurrentGeneration();

        $caseCount = (int) $products->sum('case_count');
        $unitCount = (int) $products->sum('unit_count');
        if ($caseCount === 0 && $unitCount === 0) {
            $unitCount = (int) $products->sum('document_epc_count');
        }

        $productNdcs = $products
            ->pluck('ndc')
            ->filter()
            ->map(fn ($ndc) => (string) $ndc)
            ->unique()
            ->values()
            ->all();

        return [
            'product_count' => $products->count(),
            'product_ndcs' => $productNdcs,
            'lot_count' => count($lots),
            'lots' => $lots,
            'item_count' => (int) ($this->epc_count ?? 0),
            'case_count' => $caseCount,
            'unit_count' => $unitCount,
            'case_unit_label' => $this->formatCaseUnitLabel($caseCount, $unitCount),
            'asn_number' => filled($this->asn_number) ? (string) $this->asn_number : null,
            'customer_po' => filled($this->customer_po) ? (string) $this->customer_po : null,
            'legal_notice' => filled($this->legal_notice) ? (string) $this->legal_notice : null,
            'dscsa_affirm' => (bool) $this->dscsa_affirm,
        ];
    }

    /**
     * Four-party shipping summary for Summary tab and infolist (seller / ship-from / sold-to / ship-to).
     *
     * @return array{
     *     seller: array{label: string, name: ?string, gln: ?string},
     *     ship_from: array{label: string, name: ?string, gln: ?string},
     *     sold_to: array{label: string, name: ?string, gln: ?string},
     *     ship_to: array{label: string, name: ?string, gln: ?string},
     *     ships_from_different_location: bool
     * }
     */
    public function shippingPartiesSummary(): array
    {
        $this->loadMissing(['tradingPartner', 'shipToPartner', 'shipFromSite', 'shipToSite']);

        // Outbound tradingPartner is the customer (sold-to), not the seller.
        $isOutbound = $this->direction === 'outbound';

        if ($isOutbound) {
            // Never use shipFromSite.tradingPartner as seller (org facilities have null partner).
            $sellerName = filled($this->ship_from_name)
                ? (string) $this->ship_from_name
                : $this->outboundSellerTenantName();
            $sellerGln = $this->normalizeShippingGln($this->sender_gln);
        } else {
            $sellerName = filled($this->ship_from_name)
                ? (string) $this->ship_from_name
                : ($this->tradingPartner?->name !== null ? (string) $this->tradingPartner->name : null);
            $sellerGln = $this->normalizeShippingGln($this->sender_gln)
                ?? $this->normalizeShippingGln($this->tradingPartner?->gln);
        }

        $shipFromName = filled($this->ship_from_site_name)
            ? (string) $this->ship_from_site_name
            : ($this->shipFromSite?->name !== null ? (string) $this->shipFromSite->name : null);
        $shipFromGln = $this->normalizeShippingGln($this->ship_from_gln);

        if ($isOutbound) {
            // Outbound sold-to may still resolve via customer tradingPartner.
            $soldToName = filled($this->ship_to_name)
                ? (string) $this->ship_to_name
                : ($this->shipToPartner?->name !== null
                    ? (string) $this->shipToPartner->name
                    : ($this->tradingPartner?->name !== null ? (string) $this->tradingPartner->name : null));
            $soldToGln = $this->normalizeShippingGln($this->receiver_gln)
                ?? $this->normalizeShippingGln($this->shipToPartner?->gln)
                ?? $this->normalizeShippingGln($this->tradingPartner?->gln);
        } else {
            // Inbound tradingPartner is the seller — never use it as sold-to.
            $soldToName = filled($this->ship_to_name)
                ? (string) $this->ship_to_name
                : ($this->shipToPartner?->name !== null ? (string) $this->shipToPartner->name : null);
            $soldToGln = $this->normalizeShippingGln($this->receiver_gln)
                ?? $this->normalizeShippingGln($this->shipToPartner?->gln);
        }

        $shipToName = filled($this->ship_to_site_name)
            ? (string) $this->ship_to_site_name
            : ($this->shipToSite?->name !== null ? (string) $this->shipToSite->name : null);
        $shipToGln = $this->normalizeShippingGln($this->ship_to_gln);

        return [
            'seller' => [
                'label' => 'Seller',
                'name' => $sellerName,
                'gln' => $sellerGln,
            ],
            'ship_from' => [
                'label' => 'Ship-from',
                'name' => $shipFromName,
                'gln' => $shipFromGln,
            ],
            'sold_to' => [
                'label' => 'Sold-to',
                'name' => $soldToName,
                'gln' => $soldToGln,
            ],
            'ship_to' => [
                'label' => 'Ship-to',
                'name' => $shipToName,
                'gln' => $shipToGln,
            ],
            'ships_from_different_location' => $this->shipsFromDifferentLocation(
                $sellerGln,
                $shipFromGln,
                $sellerName,
                $shipFromName,
            ),
        ];
    }

    private function outboundSellerTenantName(): ?string
    {
        if (! function_exists('tenancy') || ! tenancy()->initialized) {
            return null;
        }

        $name = tenant()?->name;

        return filled($name) ? (string) $name : null;
    }

    private function shipsFromDifferentLocation(
        ?string $sellerGln,
        ?string $shipFromGln,
        ?string $sellerName,
        ?string $shipFromName,
    ): bool {
        if ($sellerGln !== null && $shipFromGln !== null) {
            return $sellerGln !== $shipFromGln;
        }

        if (filled($sellerName) && filled($shipFromName)) {
            return strcasecmp(trim($sellerName), trim($shipFromName)) !== 0;
        }

        return false;
    }

    private function normalizeShippingGln(mixed $gln): ?string
    {
        if ($gln === null || $gln === '') {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', (string) $gln) ?? '';

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return list<string>
     */
    private function distinctLotsForCurrentGeneration(): array
    {
        $documentId = (int) $this->getKey();
        $generation = (int) ($this->ingest_generation ?? 1);

        if (Schema::hasTable('event_epc_ilmd') && Schema::hasTable('epcis_events')) {
            $lots = DB::table('event_epc_ilmd as ili')
                ->join('epcis_events', 'epcis_events.id', '=', 'ili.event_id')
                ->where('epcis_events.document_id', $documentId)
                ->when(
                    Schema::hasColumn('epcis_events', 'ingest_generation'),
                    fn ($q) => $q->where('epcis_events.ingest_generation', $generation),
                )
                ->whereNotNull('ili.lot_number')
                ->where('ili.lot_number', '!=', '')
                ->distinct()
                ->orderBy('ili.lot_number')
                ->pluck('ili.lot_number')
                ->map(fn ($lot) => (string) $lot)
                ->values()
                ->all();

            if ($lots !== []) {
                return $lots;
            }
        }

        if (Schema::hasTable('epc_ilmd') && Schema::hasTable('document_epcs')) {
            return DB::table('document_epcs as de')
                ->join('epc_ilmd', 'epc_ilmd.epc_id', '=', 'de.epc_id')
                ->where('de.document_id', $documentId)
                ->where('de.ingest_generation', $generation)
                ->whereNotNull('epc_ilmd.lot_number')
                ->where('epc_ilmd.lot_number', '!=', '')
                ->distinct()
                ->orderBy('epc_ilmd.lot_number')
                ->pluck('epc_ilmd.lot_number')
                ->map(fn ($lot) => (string) $lot)
                ->values()
                ->all();
        }

        return [];
    }

    private function formatCaseUnitLabel(int $cases, int $units): string
    {
        $parts = [];
        if ($cases > 0) {
            $parts[] = number_format($cases).' '.($cases === 1 ? 'case' : 'cases');
        }
        if ($units > 0) {
            $parts[] = number_format($units).' '.($units === 1 ? 'unit' : 'units');
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    /**
     * Per-GTIN SGTIN counts for the active generation, with case/unit roles from aggregation.
     *
     * @return Collection<string, array{total: int, cases: int, units: int, product_id: ?int}>
     */
    private function sgtinGtinStatsForGeneration(int $documentId, int $generation): Collection
    {
        $epcRows = collect();
        if (Schema::hasTable('document_epcs')) {
            $epcRows = DB::table('document_epcs as de')
                ->join('epcs', 'epcs.id', '=', 'de.epc_id')
                ->where('de.document_id', $documentId)
                ->where('de.ingest_generation', $generation)
                ->where('epcs.epc_type', 'sgtin')
                ->whereNotNull('epcs.gtin14')
                ->where('epcs.gtin14', '!=', '')
                ->get([
                    'epcs.id as epc_id',
                    'epcs.gtin14 as gtin',
                    'epcs.product_id',
                ]);
        } else {
            $query = DB::table('event_epcs')
                ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
                ->join('epcs', 'epcs.id', '=', 'event_epcs.epc_id')
                ->where('epcis_events.document_id', $documentId)
                ->where('epcs.epc_type', 'sgtin')
                ->whereNotNull('epcs.gtin14')
                ->where('epcs.gtin14', '!=', '');

            if (Schema::hasColumn('epcis_events', 'ingest_generation')) {
                $query->where('epcis_events.ingest_generation', $generation);
            }

            $epcRows = $query->distinct()->get([
                'epcs.id as epc_id',
                'epcs.gtin14 as gtin',
                'epcs.product_id',
            ]);
        }

        if ($epcRows->isEmpty()) {
            return collect();
        }

        $epcIds = $epcRows->pluck('epc_id')->map(fn ($id) => (int) $id)->all();
        $caseEpcIds = [];
        $unitEpcIds = [];

        if (Schema::hasTable('aggregation_links') && Schema::hasTable('epcis_events')) {
            $eventIds = DB::table('epcis_events')
                ->where('document_id', $documentId)
                ->when(
                    Schema::hasColumn('epcis_events', 'ingest_generation'),
                    fn ($q) => $q->where('ingest_generation', $generation),
                )
                ->pluck('id');

            if ($eventIds->isNotEmpty()) {
                $caseEpcIds = DB::table('aggregation_links as al')
                    ->join('epcs as child', 'child.id', '=', 'al.child_epc_id')
                    ->whereIn('al.established_by_event_id', $eventIds)
                    ->whereNull('al.valid_to')
                    ->where('child.epc_type', 'sgtin')
                    ->whereIn('al.parent_epc_id', $epcIds)
                    ->distinct()
                    ->pluck('al.parent_epc_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $caseEpcIdLookup = array_fill_keys($caseEpcIds, true);

                $unitEpcIds = DB::table('aggregation_links as al')
                    ->join('epcs as parent', 'parent.id', '=', 'al.parent_epc_id')
                    ->whereIn('al.established_by_event_id', $eventIds)
                    ->whereNull('al.valid_to')
                    ->where('parent.epc_type', 'sgtin')
                    ->whereIn('al.child_epc_id', $epcIds)
                    ->distinct()
                    ->pluck('al.child_epc_id')
                    ->map(fn ($id) => (int) $id)
                    ->reject(fn (int $id) => isset($caseEpcIdLookup[$id]))
                    ->values()
                    ->all();
            }
        }

        $caseLookup = array_fill_keys($caseEpcIds, true);
        $unitLookup = array_fill_keys($unitEpcIds, true);

        return $epcRows
            ->groupBy(fn ($row) => (string) $row->gtin)
            ->map(function (Collection $rows) use ($caseLookup, $unitLookup): array {
                $cases = 0;
                $units = 0;
                foreach ($rows as $row) {
                    $epcId = (int) $row->epc_id;
                    if (isset($caseLookup[$epcId])) {
                        $cases++;
                    } elseif (isset($unitLookup[$epcId])) {
                        $units++;
                    }
                }

                return [
                    'total' => $rows->count(),
                    'cases' => $cases,
                    'units' => $units,
                    'product_id' => $rows->pluck('product_id')->filter()->first(),
                ];
            });
    }

    private function formatEpcBreakdown(int $cases, int $units): string
    {
        $parts = [];
        if ($cases > 0) {
            $parts[] = $cases.' '.($cases === 1 ? 'case' : 'cases');
        }
        if ($units > 0) {
            $parts[] = $units.' '.($units === 1 ? 'unit' : 'units');
        }

        return $parts === [] ? '0 EPCs' : implode(', ', $parts);
    }

    /**
     * @param  list<string>  $ndc11s
     * @return Collection<string, FdaProductPackaging>
     */
    private function fdaPackagesByNdc11(array $ndc11s): Collection
    {
        if ($ndc11s === []) {
            return collect();
        }

        $candidates = [];
        foreach ($ndc11s as $ndc11) {
            foreach (Ndc::packageNdcCandidates($ndc11) as $candidate) {
                $candidates[$candidate] = $ndc11;
            }
        }

        if ($candidates === []) {
            return collect();
        }

        return FdaProductPackaging::query()
            ->with('product:id,brand_name,generic_name')
            ->whereIn('package_ndc', array_keys($candidates))
            ->get()
            ->mapWithKeys(function (FdaProductPackaging $package) use ($candidates): array {
                $ndc11 = $candidates[$package->package_ndc] ?? Ndc::toNdc11($package->package_ndc);
                if ($ndc11 === null) {
                    return [];
                }

                return [$ndc11 => $package];
            });
    }

    /**
     * EPCISMasterData EPCClass rows from the stored payload, keyed by GTIN-14.
     *
     * @return Collection<string, array{
     *     idpat: string,
     *     ndc11: ?string,
     *     ndc_raw: ?string,
     *     name: ?string,
     *     dosage_form: ?string,
     *     strength: ?string,
     *     manufacturer: ?string,
     *     net_content: ?string
     * }>
     */
    public function fileProductClassesByGtin(): Collection
    {
        $generation = (int) ($this->ingest_generation ?? 1);

        if (Schema::hasTable('epcis_document_product_classes')) {
            $persisted = DB::table('epcis_document_product_classes')
                ->where('document_id', $this->getKey())
                ->where('ingest_generation', $generation)
                ->whereNotNull('gtin14')
                ->where('gtin14', '!=', '')
                ->get([
                    'idpat',
                    'gtin14',
                    'ndc11',
                    'ndc_raw',
                    'name',
                    'dosage_form',
                    'strength',
                    'manufacturer',
                    'net_content',
                ]);

            if ($persisted->isNotEmpty()) {
                return $persisted->mapWithKeys(fn ($row): array => [
                    (string) $row->gtin14 => [
                        'idpat' => (string) $row->idpat,
                        'ndc11' => $row->ndc11,
                        'ndc_raw' => $row->ndc_raw,
                        'name' => $row->name,
                        'dosage_form' => $row->dosage_form,
                        'strength' => $row->strength,
                        'manufacturer' => $row->manufacturer,
                        'net_content' => $row->net_content,
                    ],
                ]);
            }
        }

        // Legacy fallback: re-parse header when no persisted vocabulary rows exist.
        $path = $this->payloadAbsolutePath();
        if ($path === null) {
            return collect();
        }

        try {
            $classes = app(EpcisXmlReader::class)->parseHeader($path)['product_classes'] ?? [];
        } catch (\Throwable) {
            return collect();
        }

        return collect($classes)
            ->mapWithKeys(function (array $class): array {
                $idpat = (string) ($class['idpat'] ?? '');
                if (preg_match('/^urn:epc:idpat:sgtin:(\d+)\.(\d+)\.\*$/', $idpat, $matches) !== 1) {
                    return [];
                }

                $parsed = Sgtin::fromUrn('urn:epc:id:sgtin:'.$matches[1].'.'.$matches[2].'.0');
                if ($parsed === null) {
                    return [];
                }

                return [
                    $parsed['gtin14'] => [
                        'idpat' => $idpat,
                        'ndc11' => $class['ndc11'] ?? null,
                        'ndc_raw' => $class['ndc_raw'] ?? null,
                        'name' => $class['name'] ?? null,
                        'dosage_form' => $class['dosage_form'] ?? null,
                        'strength' => $class['strength'] ?? null,
                        'manufacturer' => $class['manufacturer'] ?? null,
                        'net_content' => $class['net_content'] ?? null,
                    ],
                ];
            });
    }

    public function payloadFilesystemDisk(): string
    {
        return filled($this->payload_disk) ? (string) $this->payload_disk : 'local';
    }

    /**
     * Absolute filesystem path to the payload XML for local parsers.
     *
     * Local disks return Storage::path(). Non-local disks (e.g. s3) stream the
     * object into a temp file under sys_get_temp_dir(). Callers that materialize
     * from S3 should unlink that temp when finished.
     */
    public function materializePayloadPath(): string
    {
        $relative = $this->payload_path;
        if (! filled($relative)) {
            throw new \RuntimeException('EPCIS document has no payload_path.');
        }

        $disk = $this->payloadFilesystemDisk();
        $filesystem = Storage::disk($disk);
        $driver = (string) config("filesystems.disks.{$disk}.driver", 'local');

        if ($driver === 'local') {
            $absolute = $filesystem->path((string) $relative);
            if (! is_file($absolute) || ! is_readable($absolute)) {
                throw new \InvalidArgumentException(
                    "EPCIS payload is missing or unreadable: {$relative}",
                );
            }

            return $absolute;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_payload_');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create temp file for EPCIS payload.');
        }

        $tmpXml = $tmp.'.xml';
        if (! @rename($tmp, $tmpXml)) {
            $tmpXml = $tmp;
        }

        $stream = $filesystem->readStream((string) $relative);
        if ($stream === false || ! is_resource($stream)) {
            @unlink($tmpXml);
            throw new \RuntimeException(
                "Unable to read EPCIS payload from disk [{$disk}]: {$relative}",
            );
        }

        try {
            $out = fopen($tmpXml, 'wb');
            if ($out === false) {
                throw new \RuntimeException('Unable to open temp file for EPCIS payload.');
            }

            try {
                stream_copy_to_stream($stream, $out);
            } finally {
                fclose($out);
            }
        } catch (\Throwable $e) {
            @unlink($tmpXml);
            throw $e;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $tmpXml;
    }

    public function temporaryPayloadUrl(?int $minutes = null): ?string
    {
        $relative = $this->payload_path;
        if (! filled($relative)) {
            return null;
        }

        $disk = $this->payloadFilesystemDisk();
        if (config("filesystems.disks.{$disk}.driver") !== 's3') {
            return null;
        }

        $minutes ??= (int) config('tracepharma.epcis.inbound_url_ttl_minutes', 15);

        return Storage::disk($disk)->temporaryUrl(
            (string) $relative,
            now()->addMinutes($minutes),
        );
    }

    /**
     * Local-disk absolute path only. Returns null for S3 (use materializePayloadPath()).
     */
    public function payloadAbsolutePath(): ?string
    {
        $relative = $this->payload_path;
        if (! filled($relative)) {
            return null;
        }

        $disk = $this->payloadFilesystemDisk();
        if (config("filesystems.disks.{$disk}.driver", 'local') !== 'local') {
            return null;
        }

        try {
            $absolute = Storage::disk($disk)->path((string) $relative);
        } catch (\Throwable) {
            return null;
        }

        return is_readable($absolute) ? $absolute : null;
    }

    public function transmissionMdns(): HasMany
    {
        return $this->hasMany(TransmissionMdn::class, 'document_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(EpcisException::class, 'document_id');
    }

    public function unmatchedGlns(): HasMany
    {
        return $this->hasMany(EpcisUnmatchedGln::class, 'document_id');
    }

    public function productClasses(): HasMany
    {
        return $this->hasMany(EpcisDocumentProductClass::class, 'document_id');
    }

    public function documentLocations(): HasMany
    {
        return $this->hasMany(EpcisDocumentLocation::class, 'document_id');
    }

    public function vocabularyElements(): HasMany
    {
        return $this->hasMany(EpcisDocumentVocabularyElement::class, 'document_id');
    }
}
