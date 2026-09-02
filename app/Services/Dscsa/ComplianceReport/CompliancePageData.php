<?php

namespace App\Services\Dscsa\ComplianceReport;

/**
 * @phpstan-type OwnershipRow array{sender: string, receiver: string, date: string, order: int}
 */
final readonly class CompliancePageData
{
    /**
     * @param  'lot_first'|'lot_continue'  $kind
     * @param  list<OwnershipRow>  $ownershipRows
     * @param  list<SerialRow>  $serialRows
     */
    public function __construct(
        public string $kind,
        public string $referenceNumber,
        public string $shipmentId,
        public string $productName,
        public string $ndc,
        public int $numberOfContainers,
        public string $containerSize,
        public string $strength,
        public string $dosageForm,
        public int $qty,
        public string $lot,
        public string $expirationDate,
        public string $type,
        public string $manufacturer,
        public string $manufacturerAddress,
        public string $manufacturerCity,
        public string $manufacturerState,
        public string $manufacturerZip,
        public string $processedDate,
        public string $transactionDate,
        public string $trackingNumber,
        public array $ownershipRows,
        public ?string $ownershipNote,
        public string $legalStatement,
        public ?string $directPurchaseStatement,
        public ?string $receivedPrevWholesalerStatement,
        public string $sellerName,
        public array $serialRows,
        public int $pageNumber,
        public int $totalPages,
        public bool $serialsContinued,
    ) {}
}
