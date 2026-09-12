<?php

namespace App\Services\Dscsa\TransactionReport;

/**
 * @phpstan-type OwnershipRow array{
 *     sender: string,
 *     receiver: string,
 *     date: string,
 *     order: int
 * }
 */
final readonly class LotPageData
{
    /**
     * @param  list<OwnershipRow>  $ownershipRows
     */
    public function __construct(
        public string $referenceNumber,
        public string $shipmentId,
        public string $productName,
        public string $ndc,
        public int $numberOfContainers,
        public string $strength,
        public string $dosageForm,
        public string $containerSize,
        public string $lot,
        public string $expirationDate,
        public int $qty,
        public string $manufacturer,
        public string $manufacturerAddress,
        public string $manufacturerCity,
        public string $manufacturerState,
        public string $manufacturerZip,
        public string $processedDate,
        public string $transactionDate,
        public string $trackingNumber,
        public string $type,
        public array $ownershipRows,
        public ?string $ownershipNote,
        public string $legalStatement,
        public ?string $directPurchaseStatement,
        public ?string $receivedPrevWholesalerStatement,
    ) {}
}
