<?php

namespace App\Services\Dscsa\TransactionReport;

use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\EpcisDocument;
use App\Models\User;
use App\Services\Dscsa\Support\ComplianceReportBranding;
use App\Services\Dscsa\Support\EpcisShipmentReportContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class TransactionReportDataBuilder
{
    public function __construct(
        private readonly EpcisShipmentReportContext $context,
        private readonly ComplianceReportBranding $branding,
    ) {}

    public function build(EpcisDocument $document, ?User $actor = null): TransactionReportData
    {
        $shipmentId = $this->context->shipmentId($document);
        $referenceNumber = $this->context->referenceNumber($document);
        $trackingNumber = $this->context->trackingNumber($document);
        $processedDate = $this->context->formatDate($document->creation_date);
        $legalStatement = $this->context->legalStatement($document);

        $shipping = $this->context->resolveShippingContext($document);
        $transactionDate = $shipping['transaction_date'] ?? '—';
        $ownershipRows = $shipping['ownership_rows'];
        $ownershipNote = $shipping['ownership_note'];
        $directPurchase = $this->context->directPurchaseStatement(
            $document,
            $shipping['seller_name'],
            $shipping['seller_gln'],
        );
        $receivedPrevWholesaler = $this->context->receivedPrevWholesalerStatement($document);
        $branding = $this->branding->resolve($shipping['seller_gln'], $shipping['seller_name']);

        $lotGroups = $this->context->lotGroups($document);
        $productClasses = $document->fileProductClassesByGtin();
        $pages = [];

        if ($lotGroups === []) {
            $pages[] = $this->pageForLot(
                lot: '—',
                lotMeta: [
                    'sgtin_ids' => [],
                    'parent_ids' => [],
                    'gtin14' => null,
                    'expiry' => null,
                ],
                referenceNumber: $referenceNumber,
                shipmentId: $shipmentId,
                trackingNumber: $trackingNumber,
                processedDate: $processedDate,
                transactionDate: $transactionDate,
                ownershipRows: $ownershipRows,
                ownershipNote: $ownershipNote,
                legalStatement: $legalStatement,
                directPurchase: $directPurchase,
                receivedPrevWholesaler: $receivedPrevWholesaler,
                productClasses: $productClasses,
                document: $document,
            );
        } else {
            foreach ($lotGroups as $lot => $meta) {
                $pages[] = $this->pageForLot(
                    lot: (string) $lot,
                    lotMeta: $meta,
                    referenceNumber: $referenceNumber,
                    shipmentId: $shipmentId,
                    trackingNumber: $trackingNumber,
                    processedDate: $processedDate,
                    transactionDate: $transactionDate,
                    ownershipRows: $ownershipRows,
                    ownershipNote: $ownershipNote,
                    legalStatement: $legalStatement,
                    directPurchase: $directPurchase,
                    receivedPrevWholesaler: $receivedPrevWholesaler,
                    productClasses: $productClasses,
                    document: $document,
                );
            }
        }

        return new TransactionReportData(
            referenceNumber: $referenceNumber,
            shipmentId: $shipmentId,
            pages: $pages,
            footer: $this->context->footer($actor),
            logoDataUri: $branding['logoDataUri'],
            sellerDisplayName: $branding['sellerDisplayName'],
        );
    }

    /**
     * @param  array{
     *     sgtin_ids: list<int>,
     *     parent_ids: list<int>,
     *     gtin14: ?string,
     *     expiry: ?string
     * }  $lotMeta
     * @param  list<array{sender: string, receiver: string, date: string, order: int}>  $ownershipRows
     * @param  Collection<string, array<string, mixed>>  $productClasses
     */
    private function pageForLot(
        string $lot,
        array $lotMeta,
        string $referenceNumber,
        string $shipmentId,
        string $trackingNumber,
        string $processedDate,
        string $transactionDate,
        array $ownershipRows,
        ?string $ownershipNote,
        string $legalStatement,
        ?string $directPurchase,
        ?string $receivedPrevWholesaler,
        Collection $productClasses,
        EpcisDocument $document,
    ): LotPageData {
        $sgtinIds = $lotMeta['sgtin_ids'];
        $parentIds = $lotMeta['parent_ids'];
        $containers = count($sgtinIds);
        $qty = $containers;

        if ($parentIds !== [] && Schema::hasTable('aggregation_links')) {
            $childCount = (int) AggregationLink::query()
                ->open()
                ->forDocumentProjection($document)
                ->whereIn('parent_epc_id', $parentIds)
                ->distinct()
                ->count('child_epc_id');
            if ($childCount > 0) {
                $containers = count($parentIds);
                $qty = $childCount;
            }
        }

        $product = $this->context->productForGtin($lotMeta['gtin14'], $productClasses);
        $manufacturerName = $this->context->display($product['manufacturer'] ?? null);
        $mfrAddress = $this->context->manufacturerAddress(
            $document,
            $manufacturerName !== '—' ? $manufacturerName : null,
        );

        return new LotPageData(
            referenceNumber: $referenceNumber,
            shipmentId: $shipmentId,
            productName: $this->context->display($product['name'] ?? null),
            ndc: $this->context->display($product['ndc_raw'] ?? $product['ndc11'] ?? $product['ndc'] ?? null),
            numberOfContainers: max(0, $containers),
            strength: $this->context->display($product['strength'] ?? null),
            dosageForm: $this->context->display($product['dosage_form'] ?? null),
            containerSize: $this->context->display($product['net_content'] ?? null),
            lot: $lot === '' ? '—' : $lot,
            expirationDate: $this->context->display($lotMeta['expiry'] ?? null),
            qty: max(0, $qty),
            manufacturer: $manufacturerName,
            manufacturerAddress: $mfrAddress['address'],
            manufacturerCity: $mfrAddress['city'],
            manufacturerState: $mfrAddress['state'],
            manufacturerZip: $mfrAddress['zip'],
            processedDate: $processedDate,
            transactionDate: $transactionDate,
            trackingNumber: $trackingNumber,
            type: 'EPCIS',
            ownershipRows: $ownershipRows,
            ownershipNote: $ownershipNote,
            legalStatement: $legalStatement,
            directPurchaseStatement: $directPurchase,
            receivedPrevWholesalerStatement: $receivedPrevWholesaler,
        );
    }
}
