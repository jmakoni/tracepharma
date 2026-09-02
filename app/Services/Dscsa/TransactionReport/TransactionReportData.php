<?php

namespace App\Services\Dscsa\TransactionReport;

/**
 * @phpstan-type FooterData array{
 *     generated_from: string,
 *     generated_by: string,
 *     generated_at: string
 * }
 */
final readonly class TransactionReportData
{
    /**
     * @param  list<LotPageData>  $pages
     * @param  FooterData  $footer
     */
    public function __construct(
        public string $referenceNumber,
        public string $shipmentId,
        public array $pages,
        public array $footer,
        public ?string $logoDataUri = null,
        public string $sellerDisplayName = 'Seller',
    ) {}
}
