<?php

namespace App\Services\Dscsa\ComplianceReport;

use App\Models\Epcis\EpcisDocument;
use App\Models\User;
use App\Services\Dscsa\Support\EpcisShipmentReportContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ComplianceReportDataBuilder
{
    /**
     * First page also carries TI + ownership + legal. Dompdf overflows into a blank
     * trailing page if this budget is too high (measured ~5 rows with address blocks).
     */
    private const FIRST_PAGE_SERIAL_BUDGET = 5;

    private const CONTINUE_PAGE_SERIAL_BUDGET = 26;

    public function __construct(
        private readonly EpcisShipmentReportContext $context,
        private readonly SerialSelector $serialSelector,
    ) {}

    public function build(EpcisDocument $document, ?User $actor = null): ComplianceReportData
    {
        $shipmentId = $this->context->shipmentId($document);
        $referenceNumber = $this->context->referenceNumber($document);
        $trackingNumber = $this->context->trackingNumber($document);
        $processedDate = $this->context->formatDate($document->creation_date);
        $legalStatement = $this->context->legalStatement($document);
        $shipping = $this->context->resolveShippingContext($document);
        $directPurchase = $this->context->directPurchaseStatement($document, $shipping['seller_name']);
        $productClasses = $document->fileProductClassesByGtin();
        $lotGroups = $this->context->lotGroups($document);
        $footer = $this->context->footer($actor);

        if ($lotGroups === []) {
            $lotGroups = [
                '—' => [
                    'sgtin_ids' => [],
                    'parent_ids' => [],
                    'gtin14' => null,
                    'expiry' => null,
                ],
            ];
        }

        /** @var list<array{lot: string, ti: array<string, mixed>, serials: list<SerialRow>}> $lotSections */
        $lotSections = [];
        $totalSerials = 0;
        $lots = [];

        foreach ($lotGroups as $lot => $meta) {
            $lots[] = (string) $lot;
            $serials = $this->serialSelector->forLot($document, (string) $lot, $meta['sgtin_ids']);
            $totalSerials += count($serials);
            $lotSections[] = [
                'lot' => (string) $lot,
                'ti' => $this->tiFields($document, (string) $lot, $meta, $productClasses, $serials),
                'serials' => $serials,
            ];
        }

        $totalPages = 0;
        foreach ($lotSections as $section) {
            $count = count($section['serials']);
            if ($count <= self::FIRST_PAGE_SERIAL_BUDGET) {
                $totalPages += 1;
            } else {
                $remaining = $count - self::FIRST_PAGE_SERIAL_BUDGET;
                $totalPages += 1 + (int) ceil($remaining / self::CONTINUE_PAGE_SERIAL_BUDGET);
            }
        }

        $pages = [];
        $pageNumber = 1;

        foreach ($lotSections as $section) {
            $serials = $section['serials'];
            $ti = $section['ti'];
            $chunks = $this->chunkSerials($serials);

            foreach ($chunks as $index => $chunk) {
                $kind = $index === 0 ? 'lot_first' : 'lot_continue';
                $pages[] = new CompliancePageData(
                    kind: $kind,
                    referenceNumber: $referenceNumber,
                    shipmentId: $shipmentId,
                    productName: $ti['productName'],
                    ndc: $ti['ndc'],
                    numberOfContainers: $ti['numberOfContainers'],
                    containerSize: $ti['containerSize'],
                    strength: $ti['strength'],
                    dosageForm: $ti['dosageForm'],
                    qty: $ti['qty'],
                    lot: $ti['lot'],
                    expirationDate: $ti['expirationDate'],
                    type: 'EPCIS',
                    manufacturer: $ti['manufacturer'],
                    manufacturerAddress: $ti['manufacturerAddress'],
                    manufacturerCity: $ti['manufacturerCity'],
                    manufacturerState: $ti['manufacturerState'],
                    manufacturerZip: $ti['manufacturerZip'],
                    processedDate: $processedDate,
                    transactionDate: $shipping['transaction_date'],
                    trackingNumber: $trackingNumber,
                    ownershipRows: $shipping['ownership_rows'],
                    ownershipNote: $shipping['ownership_note'],
                    legalStatement: $legalStatement,
                    directPurchaseStatement: $directPurchase,
                    sellerName: $shipping['seller_name'],
                    serialRows: $chunk,
                    pageNumber: $pageNumber,
                    totalPages: max(1, $totalPages),
                    serialsContinued: $index > 0,
                );
                $pageNumber++;
            }
        }

        return new ComplianceReportData(
            referenceNumber: $referenceNumber,
            shipmentId: $shipmentId,
            pages: $pages,
            footer: $footer,
            lots: $lots,
            serialCount: $totalSerials,
        );
    }

    /**
     * @param  array{sgtin_ids: list<int>, parent_ids: list<int>, gtin14: ?string, expiry: ?string}  $meta
     * @param  Collection<string, array<string, mixed>>  $productClasses
     * @param  list<SerialRow>  $serials
     * @return array<string, mixed>
     */
    private function tiFields(
        EpcisDocument $document,
        string $lot,
        array $meta,
        $productClasses,
        array $serials,
    ): array {
        $parentIds = $meta['parent_ids'];
        $containers = count($serials);
        $qty = $containers;

        if ($parentIds !== [] && Schema::hasTable('aggregation_links')) {
            $childCount = (int) DB::table('aggregation_links')
                ->whereIn('parent_epc_id', $parentIds)
                ->whereNull('valid_to')
                ->distinct()
                ->count('child_epc_id');
            if ($childCount > 0) {
                $containers = count($parentIds);
                $qty = max($qty, $childCount);
            }
        }

        if ($containers === 0) {
            $containers = count($meta['sgtin_ids']);
            $qty = $containers;
        }

        $product = $this->context->productForGtin($meta['gtin14'], $productClasses);
        $manufacturerName = $this->context->display($product['manufacturer'] ?? null);
        $mfrAddress = $this->context->manufacturerAddress(
            $document,
            $manufacturerName !== '—' ? $manufacturerName : null,
        );

        return [
            'productName' => $this->context->display($product['name'] ?? null),
            'ndc' => $this->context->display($product['ndc_raw'] ?? $product['ndc11'] ?? $product['ndc'] ?? null),
            'numberOfContainers' => max(0, $containers),
            'containerSize' => $this->context->display($product['net_content'] ?? null),
            'strength' => $this->context->display($product['strength'] ?? null),
            'dosageForm' => $this->context->display($product['dosage_form'] ?? null),
            'qty' => max(0, $qty),
            'lot' => $lot === '' ? '—' : $lot,
            'expirationDate' => $this->context->display($meta['expiry'] ?? null),
            'manufacturer' => $manufacturerName,
            'manufacturerAddress' => $mfrAddress['address'],
            'manufacturerCity' => $mfrAddress['city'],
            'manufacturerState' => $mfrAddress['state'],
            'manufacturerZip' => $mfrAddress['zip'],
        ];
    }

    /**
     * @param  list<SerialRow>  $serials
     * @return list<list<SerialRow>>
     */
    private function chunkSerials(array $serials): array
    {
        if ($serials === []) {
            return [[]];
        }

        $first = array_slice($serials, 0, self::FIRST_PAGE_SERIAL_BUDGET);
        $rest = array_slice($serials, self::FIRST_PAGE_SERIAL_BUDGET);
        $chunks = [$first];

        while ($rest !== []) {
            $chunks[] = array_slice($rest, 0, self::CONTINUE_PAGE_SERIAL_BUDGET);
            $rest = array_slice($rest, self::CONTINUE_PAGE_SERIAL_BUDGET);
        }

        return $chunks;
    }
}
