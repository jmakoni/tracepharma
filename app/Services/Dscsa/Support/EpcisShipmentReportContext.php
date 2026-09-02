<?php

namespace App\Services\Dscsa\Support;

use App\Actions\Epcis\ResolveGlnToMasterData;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisDocumentLocation;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EventBizTransaction;
use App\Models\Epcis\EventParty;
use App\Models\TradingPartner;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared resolvers for Transaction Report and DSCSA Compliance Report PDFs.
 */
final class EpcisShipmentReportContext
{
    public const DEFAULT_LEGAL = 'Seller has complied with each applicable subsection of FDCA Sec. 581(27)(A)-(G).';

    public function __construct(
        private readonly ResolveGlnToMasterData $resolveGln,
        private readonly DscsaDirectPurchaseStatements $directPurchaseStatements,
    ) {}

    public function shipmentId(EpcisDocument $document): string
    {
        return filled($document->document_uuid)
            ? (string) $document->document_uuid
            : 'DOC-'.$document->getKey();
    }

    public function referenceNumber(EpcisDocument $document): string
    {
        if (filled($document->customer_po)) {
            return (string) $document->customer_po;
        }

        if (filled($document->asn_number)) {
            return (string) $document->asn_number;
        }

        $uuid = (string) ($document->document_uuid ?? '');

        return $uuid !== '' ? substr($uuid, 0, 8) : 'DOC-'.$document->getKey();
    }

    public function trackingNumber(EpcisDocument $document): string
    {
        if (filled($document->asn_number)) {
            return (string) $document->asn_number;
        }

        $eventsQuery = Schema::hasColumn('epcis_events', 'ingest_generation')
            ? $document->activeEvents()
            : $document->events();

        $events = $eventsQuery->with('bizTransactions')->orderBy('id')->get();
        foreach ($events as $event) {
            foreach ($event->bizTransactions as $bt) {
                /** @var EventBizTransaction $bt */
                $typeUri = strtolower((string) ($bt->type_uri ?? ''));
                if (str_ends_with($typeUri, ':desadv')) {
                    $segment = $this->urnLastSegment((string) ($bt->value ?? ''));
                    if ($segment !== null) {
                        return $segment;
                    }
                }
            }
        }

        return '—';
    }

    public function legalStatement(EpcisDocument $document): string
    {
        return filled($document->legal_notice)
            ? (string) $document->legal_notice
            : self::DEFAULT_LEGAL;
    }

    public function directPurchaseStatement(EpcisDocument $document, string $sellerName, ?string $sellerGln = null): ?string
    {
        if (! (bool) $document->dscsa_affirm) {
            return null;
        }

        if (filled($document->direct_purchase_statement)) {
            return (string) $document->direct_purchase_statement;
        }

        if ($this->directPurchaseStatements->shouldOmitGeneratedDirectPurchase($document->direct_purchase_qualifier)) {
            return null;
        }

        $partnerType = $this->directPurchaseStatements->resolveSellerPartnerType(
            $document,
            $sellerGln,
            $sellerName,
        );

        return $this->directPurchaseStatements->generatedStatement(
            $document,
            $partnerType,
            $sellerName,
        );
    }

    public function receivedPrevWholesalerStatement(EpcisDocument $document): ?string
    {
        if (! (bool) $document->dscsa_affirm) {
            return null;
        }

        if (filled($document->received_prev_wholesaler_statement)) {
            return (string) $document->received_prev_wholesaler_statement;
        }

        if (filled($document->received_prev_wholesaler_qualifier)) {
            return DscsaDirectPurchaseStatements::RECEIVED_PREV_WHOLESALER_DEFAULT;
        }

        return null;
    }

    /**
     * @return array{
     *     transaction_date: string,
     *     ownership_rows: list<array{sender: string, receiver: string, date: string, order: int}>,
     *     ownership_note: ?string,
     *     seller_name: string,
     *     seller_gln: ?string
     * }
     */
    public function resolveShippingContext(EpcisDocument $document): array
    {
        $eventsQuery = Schema::hasColumn('epcis_events', 'ingest_generation')
            ? $document->activeEvents()
            : $document->events();

        $events = $eventsQuery
            ->with(['bizTransactions', 'parties'])
            ->orderBy('event_time')
            ->orderBy('id')
            ->get();

        $shippingEvents = $events->filter(function (EpcisEvent $event): bool {
            if ($event->event_type !== 'ObjectEvent') {
                return false;
            }

            $bizStep = strtolower((string) ($event->biz_step ?? ''));

            return $bizStep !== '' && str_contains($bizStep, 'shipping');
        })->values();

        $fallbackSellerGln = $this->normalizeGln($document->sender_gln);
        $fallbackSellerName = $fallbackSellerGln !== null
            ? $this->addressForGln($document, $fallbackSellerGln)['name']
            : '—';
        if ($fallbackSellerName === '—' && filled($document->ship_from_name)) {
            $fallbackSellerName = (string) $document->ship_from_name;
        }

        if ($shippingEvents->isEmpty()) {
            return [
                'transaction_date' => '—',
                'ownership_rows' => [],
                'ownership_note' => 'Ownership transfer is not yet present for this document.',
                'seller_name' => $fallbackSellerName,
                'seller_gln' => $fallbackSellerGln,
            ];
        }

        $rows = [];
        $order = 1;
        $sellerName = $fallbackSellerName;
        $sellerGln = $fallbackSellerGln;

        foreach ($shippingEvents as $event) {
            $glns = $this->extractPartyGlns($event->parties);
            $senderOwningGln = $glns['source_owning_party_gln'] ?? $fallbackSellerGln;
            $senderLocationGln = $glns['source_location_gln'];
            $receiverOwningGln = $glns['destination_owning_party_gln']
                ?? $this->normalizeGln($document->receiver_gln);
            $receiverLocationGln = $glns['destination_location_gln'];

            if ($order === 1 && $senderOwningGln !== null) {
                $sellerGln = $senderOwningGln;
                $addr = $this->addressForGln($document, $senderOwningGln);
                $sellerName = $addr['name'] !== '—' ? $addr['name'] : $fallbackSellerName;
            }

            $rows[] = [
                'sender' => $this->ownershipPartyBlock(
                    $document,
                    $senderOwningGln,
                    $senderLocationGln,
                    'Ship-from',
                ),
                'receiver' => $this->ownershipPartyBlock(
                    $document,
                    $receiverOwningGln,
                    $receiverLocationGln,
                    'Ship-to',
                ),
                'date' => $this->formatDate($event->event_time),
                'order' => $order,
            ];
            $order++;
        }

        return [
            'transaction_date' => $rows[0]['date'] ?? '—',
            'ownership_rows' => $rows,
            'ownership_note' => null,
            'seller_name' => $sellerName,
            'seller_gln' => $sellerGln,
        ];
    }

    /**
     * @return array<string, array{sgtin_ids: list<int>, parent_ids: list<int>, gtin14: ?string, expiry: ?string}>
     */
    public function lotGroups(EpcisDocument $document): array
    {
        $documentId = (int) $document->getKey();
        $generation = (int) ($document->ingest_generation ?? 1);

        if (! Schema::hasTable('document_epcs') || ! Schema::hasTable('epcs') || ! Schema::hasTable('epc_ilmd')) {
            return [];
        }

        $rows = DB::table('document_epcs as de')
            ->join('epcs', 'epcs.id', '=', 'de.epc_id')
            ->join('epc_ilmd', 'epc_ilmd.epc_id', '=', 'epcs.id')
            ->where('de.document_id', $documentId)
            ->where('de.ingest_generation', $generation)
            ->where('epcs.epc_type', 'sgtin')
            ->whereNotNull('epc_ilmd.lot_number')
            ->where('epc_ilmd.lot_number', '!=', '')
            ->get([
                'epcs.id as epc_id',
                'epcs.gtin14',
                'epc_ilmd.lot_number',
                'epc_ilmd.expiry_date',
            ]);

        if ($rows->isEmpty() && Schema::hasTable('event_epc_ilmd') && Schema::hasTable('epcis_events')) {
            $query = DB::table('event_epc_ilmd as ili')
                ->join('epcis_events', 'epcis_events.id', '=', 'ili.event_id')
                ->join('epcs', 'epcs.id', '=', 'ili.epc_id')
                ->where('epcis_events.document_id', $documentId)
                ->where('epcs.epc_type', 'sgtin')
                ->whereNotNull('ili.lot_number')
                ->where('ili.lot_number', '!=', '');

            if (Schema::hasColumn('epcis_events', 'ingest_generation')) {
                $query->where('epcis_events.ingest_generation', $generation);
            }

            $rows = $query->get([
                'epcs.id as epc_id',
                'epcs.gtin14',
                'ili.lot_number',
                'ili.expiry_date',
            ]);
        }

        /** @var array<string, array{sgtin_ids: list<int>, parent_ids: list<int>, gtin14: ?string, expiry: ?string}> $groups */
        $groups = [];
        $parentIdsByLot = [];

        if (Schema::hasTable('aggregation_links') && $rows->isNotEmpty()) {
            $allIds = $rows->pluck('epc_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
            $parents = DB::table('aggregation_links')
                ->whereIn('parent_epc_id', $allIds)
                ->whereNull('valid_to')
                ->distinct()
                ->pluck('parent_epc_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $parentSet = array_fill_keys($parents, true);

            foreach ($rows as $row) {
                $lot = (string) $row->lot_number;
                $epcId = (int) $row->epc_id;
                if (isset($parentSet[$epcId])) {
                    $parentIdsByLot[$lot][] = $epcId;
                }
            }
        }

        foreach ($rows as $row) {
            $lot = (string) $row->lot_number;
            if (! isset($groups[$lot])) {
                $groups[$lot] = [
                    'sgtin_ids' => [],
                    'parent_ids' => [],
                    'gtin14' => null,
                    'expiry' => null,
                ];
            }

            $epcId = (int) $row->epc_id;
            if (! in_array($epcId, $groups[$lot]['sgtin_ids'], true)) {
                $groups[$lot]['sgtin_ids'][] = $epcId;
            }

            if ($groups[$lot]['gtin14'] === null && filled($row->gtin14)) {
                $groups[$lot]['gtin14'] = (string) $row->gtin14;
            }

            if ($groups[$lot]['expiry'] === null && filled($row->expiry_date)) {
                $groups[$lot]['expiry'] = $this->formatDate($row->expiry_date);
            }
        }

        foreach ($parentIdsByLot as $lot => $ids) {
            if (isset($groups[$lot])) {
                $groups[$lot]['parent_ids'] = array_values(array_unique($ids));
            }
        }

        ksort($groups);

        return $groups;
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $productClasses
     * @return array<string, mixed>
     */
    public function productForGtin(?string $gtin, Collection $productClasses): array
    {
        if ($gtin === null || $gtin === '') {
            return [];
        }

        $product = $productClasses->get($gtin) ?? [];
        if ($product !== []) {
            return $product;
        }

        return $this->productFallback($gtin);
    }

    /**
     * Manufacturer address prefers seller/owning-party location — never a 3PL ship-from site
     * when that GLN differs from the seller or source owning party.
     *
     * @return array{address: string, city: string, state: string, zip: string}
     */
    public function manufacturerAddress(EpcisDocument $document, ?string $manufacturerName = null): array
    {
        $empty = $this->emptyManufacturerAddress();

        $owningGln = $this->resolveSourceOwningPartyGln($document);
        $senderGln = $this->normalizeGln($document->sender_gln);
        $shipFromGln = $this->normalizeGln($document->ship_from_gln);
        $sellerGln = $this->resolveShippingContext($document)['seller_gln'] ?? null;

        // 1. Source owning party from shipping events, or SBDH sender when it is the seller.
        $priorityGlns = array_values(array_unique(array_filter([
            $owningGln,
            $senderGln !== null && ($owningGln === null || $senderGln === $sellerGln || $senderGln === $owningGln)
                ? $senderGln
                : null,
        ])));

        foreach ($priorityGlns as $gln) {
            if ($this->isExcludedShipFromGln($gln, $shipFromGln, $owningGln, $senderGln)) {
                continue;
            }

            $resolved = $this->addressFieldsFromGln($document, $gln);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        // 2. Match document location vocabulary or trading partner by manufacturer name.
        if (filled($manufacturerName) && $manufacturerName !== '—') {
            $resolved = $this->addressByManufacturerName(
                $document,
                $manufacturerName,
                $shipFromGln,
                $owningGln,
                $senderGln,
            );
            if ($resolved !== null) {
                return $resolved;
            }
        }

        // 3. Seller owning address via sender GLN / trading partner master data.
        if ($senderGln !== null && ! $this->isExcludedShipFromGln($senderGln, $shipFromGln, $owningGln, $senderGln)) {
            $resolved = $this->addressFieldsFromGln($document, $senderGln);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $empty;
    }

    /**
     * @return array{generated_from: string, generated_by: string, generated_at: string}
     */
    public function footer(?User $actor): array
    {
        $host = request()->getSchemeAndHttpHost();
        if (! filled($host) || str_contains($host, 'localhost') || str_contains($host, '127.0.0.1')) {
            $domain = tenant()?->domains()->value('domain');
            if (filled($domain)) {
                $host = str_starts_with((string) $domain, 'http') ? (string) $domain : 'https://'.$domain;
            }
        }

        $user = $actor ?? auth()->user();
        $by = 'System';
        if ($user instanceof User) {
            $by = trim((string) $user->name);
            if (filled($user->email)) {
                $by .= ' ('.$user->email.')';
            }
        }

        return [
            'generated_from' => filled($host) ? (string) $host : '—',
            'generated_by' => $by !== '' ? $by : 'System',
            'generated_at' => now()
                ->timezone(config('app.timezone'))
                ->format('m/d/Y H:i:s T'),
        ];
    }

    public function formatDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if ($value instanceof CarbonInterface) {
            return $value->timezone(config('app.timezone'))->format('m/d/Y');
        }

        try {
            return Carbon::parse((string) $value)
                ->timezone(config('app.timezone'))
                ->format('m/d/Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    public function display(?string $value): string
    {
        return filled($value) ? (string) $value : '—';
    }

    public function normalizeGln(?string $gln): ?string
    {
        if (! filled($gln)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $gln) ?? '';

        return strlen($digits) === 13 ? $digits : null;
    }

    /**
     * @return array{name: string, address: string, city: string, state: string, zip: string}
     */
    public function addressForGln(EpcisDocument $document, string $gln): array
    {
        $empty = [
            'name' => '—',
            'address' => '—',
            'city' => '—',
            'state' => '—',
            'zip' => '—',
        ];

        if (Schema::hasTable('epcis_document_locations')) {
            $loc = EpcisDocumentLocation::query()
                ->where('document_id', $document->getKey())
                ->where('gln', $gln)
                ->when(
                    Schema::hasColumn('epcis_document_locations', 'ingest_generation'),
                    fn ($q) => $q->where('ingest_generation', (int) ($document->ingest_generation ?? 1)),
                )
                ->first();

            if ($loc !== null) {
                return [
                    'name' => $this->display($loc->name),
                    'address' => $this->display($loc->street_address),
                    'city' => $this->display($loc->city),
                    'state' => $this->display($loc->state),
                    'zip' => $this->display($loc->postal_code),
                ];
            }
        }

        $master = $this->resolveGln->handle($gln);
        $site = $master['site'];
        $partner = $master['trading_partner'];
        $entity = $site ?? $partner;

        if ($entity === null) {
            return $empty;
        }

        return [
            'name' => $this->display($entity->name ?? null),
            'address' => $this->display($entity->street_address ?? null),
            'city' => $this->display($entity->city ?? null),
            'state' => $this->display($entity->state ?? null),
            'zip' => $this->display($entity->zipcode ?? $entity->postal_code ?? null),
        ];
    }

    /**
     * @return array{address: string, city: string, state: string, zip: string}
     */
    private function emptyManufacturerAddress(): array
    {
        return [
            'address' => '—',
            'city' => '—',
            'state' => '—',
            'zip' => '—',
        ];
    }

    private function resolveSourceOwningPartyGln(EpcisDocument $document): ?string
    {
        $eventsQuery = Schema::hasColumn('epcis_events', 'ingest_generation')
            ? $document->activeEvents()
            : $document->events();

        $events = $eventsQuery
            ->with('parties')
            ->orderBy('event_time')
            ->orderBy('id')
            ->get();

        foreach ($events as $event) {
            if ($event->event_type !== 'ObjectEvent') {
                continue;
            }

            $bizStep = strtolower((string) ($event->biz_step ?? ''));
            if ($bizStep === '' || ! str_contains($bizStep, 'shipping')) {
                continue;
            }

            $glns = $this->extractPartyGlns($event->parties);
            if ($glns['source_owning_party_gln'] !== null) {
                return $glns['source_owning_party_gln'];
            }
        }

        return null;
    }

    /**
     * @return array{address: string, city: string, state: string, zip: string}|null
     */
    private function addressFieldsFromGln(EpcisDocument $document, string $gln): ?array
    {
        $addr = $this->addressForGln($document, $gln);
        if (! $this->hasUsableAddress($addr)) {
            return null;
        }

        return [
            'address' => $addr['address'],
            'city' => $addr['city'],
            'state' => $addr['state'],
            'zip' => $addr['zip'],
        ];
    }

    /**
     * @param  array{name: string, address: string, city: string, state: string, zip: string}  $addr
     */
    private function hasUsableAddress(array $addr): bool
    {
        return $addr['address'] !== '—' || $addr['city'] !== '—';
    }

    private function isExcludedShipFromGln(
        ?string $gln,
        ?string $shipFromGln,
        ?string $owningGln,
        ?string $senderGln,
    ): bool {
        if ($gln === null || $shipFromGln === null || $gln !== $shipFromGln) {
            return false;
        }

        return $gln !== $owningGln && $gln !== $senderGln;
    }

    /**
     * @return array{address: string, city: string, state: string, zip: string}|null
     */
    private function addressByManufacturerName(
        EpcisDocument $document,
        string $manufacturerName,
        ?string $shipFromGln,
        ?string $owningGln,
        ?string $senderGln,
    ): ?array {
        $needle = $this->normalizePartyName($manufacturerName);
        if ($needle === '') {
            return null;
        }

        if (Schema::hasTable('epcis_document_locations')) {
            $locations = EpcisDocumentLocation::query()
                ->where('document_id', $document->getKey())
                ->when(
                    Schema::hasColumn('epcis_document_locations', 'ingest_generation'),
                    fn ($q) => $q->where('ingest_generation', (int) ($document->ingest_generation ?? 1)),
                )
                ->get();

            foreach ($locations as $location) {
                $gln = $this->normalizeGln($location->gln);
                if ($gln === null || $this->isExcludedShipFromGln($gln, $shipFromGln, $owningGln, $senderGln)) {
                    continue;
                }

                if ($this->normalizePartyName($location->name) !== $needle) {
                    continue;
                }

                $resolved = $this->addressFieldsFromGln($document, $gln);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        $partner = TradingPartner::query()
            ->whereNotNull('gln')
            ->get()
            ->first(fn (TradingPartner $candidate): bool => $this->normalizePartyName($candidate->name) === $needle);

        if ($partner !== null) {
            $gln = $this->normalizeGln($partner->gln);
            if ($gln !== null && ! $this->isExcludedShipFromGln($gln, $shipFromGln, $owningGln, $senderGln)) {
                return $this->addressFieldsFromGln($document, $gln);
            }
        }

        return null;
    }

    private function normalizePartyName(?string $name): string
    {
        if (! filled($name) || $name === '—') {
            return '';
        }

        return strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? '');
    }

    /**
     * Ownership block (owning party) with optional ship-from/ship-to line when location differs.
     */
    private function ownershipPartyBlock(
        EpcisDocument $document,
        ?string $owningGln,
        ?string $locationGln,
        string $shipLabel,
    ): string {
        $primaryGln = $owningGln ?? $locationGln;
        $primary = $this->partyBlock($document, $primaryGln);

        if ($owningGln === null || $locationGln === null || $locationGln === $owningGln) {
            return $primary;
        }

        $shipLines = $this->partyBlockLines($document, $locationGln);
        if ($shipLines === []) {
            return $primary;
        }

        $shipLines[0] = $shipLabel.': '.$shipLines[0];

        return $primary."\n".implode("\n", $shipLines);
    }

    private function partyBlock(EpcisDocument $document, ?string $gln): string
    {
        if ($gln === null) {
            return '—';
        }

        $lines = $this->partyBlockLines($document, $gln);

        return $lines === [] ? 'GLN '.$gln : implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function partyBlockLines(EpcisDocument $document, string $gln): array
    {
        $resolved = $this->addressForGln($document, $gln);

        return array_values(array_filter([
            $resolved['name'] !== '—' ? $resolved['name'] : null,
            $resolved['address'] !== '—' ? $resolved['address'] : null,
            trim(implode(', ', array_filter([
                $resolved['city'] !== '—' ? $resolved['city'] : null,
                $resolved['state'] !== '—' ? $resolved['state'] : null,
                $resolved['zip'] !== '—' ? $resolved['zip'] : null,
            ]))) ?: null,
            'GLN '.$gln,
        ]));
    }

    /**
     * @param  Collection<int, EventParty>  $parties
     * @return array{
     *     source_owning_party_gln: ?string,
     *     source_location_gln: ?string,
     *     destination_owning_party_gln: ?string,
     *     destination_location_gln: ?string
     * }
     */
    private function extractPartyGlns(Collection $parties): array
    {
        $sourceLocation = null;
        $sourceOwning = null;
        $destLocation = null;
        $destOwning = null;

        foreach ($parties as $party) {
            $gln = $this->normalizeGln($party->gln);
            if ($gln === null) {
                continue;
            }

            $extra = is_array($party->extra_json) ? $party->extra_json : [];
            $type = strtolower((string) ($extra['source_dest_type'] ?? ''));

            if ($party->party_role === 'source') {
                if ($type === 'location') {
                    $sourceLocation ??= $gln;
                } elseif ($type === 'owning_party') {
                    $sourceOwning ??= $gln;
                }
            }

            if ($party->party_role === 'destination') {
                if ($type === 'location') {
                    $destLocation ??= $gln;
                } elseif ($type === 'owning_party') {
                    $destOwning ??= $gln;
                }
            }
        }

        return [
            'source_owning_party_gln' => $sourceOwning,
            'source_location_gln' => $sourceLocation,
            'destination_owning_party_gln' => $destOwning,
            'destination_location_gln' => $destLocation,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productFallback(string $gtin): array
    {
        if (! Schema::hasTable('products')) {
            return [];
        }

        $query = DB::table('products')->where(function ($q) use ($gtin): void {
            if (Schema::hasColumn('products', 'gtin')) {
                $q->orWhere('gtin', $gtin);
            }
            if (Schema::hasColumn('products', 'gtin14')) {
                $q->orWhere('gtin14', $gtin);
            }
        });

        $row = $query->first();
        if ($row === null) {
            return [];
        }

        return [
            'name' => $row->name ?? null,
            'ndc' => $row->package_ndc ?? $row->ndc11 ?? $row->ndc ?? null,
            'ndc11' => $row->ndc11 ?? null,
            'ndc_raw' => $row->package_ndc ?? $row->ndc ?? null,
            'strength' => $row->strength ?? null,
            'dosage_form' => $row->dosage_form ?? null,
            'manufacturer' => null,
            'net_content' => $row->net_content ?? null,
        ];
    }

    private function urnLastSegment(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parts = explode(':', $value);
        $segment = trim((string) end($parts));

        return $segment !== '' ? $segment : null;
    }
}
