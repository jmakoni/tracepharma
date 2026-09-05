<?php

namespace App\Services\Dscsa\ComplianceReport;

/**
 * @phpstan-type FooterData array{
 *     generated_from: string,
 *     generated_by: string,
 *     generated_at: string
 * }
 */
final readonly class ComplianceReportData
{
    /**
     * @param  list<CompliancePageData>  $pages
     * @param  FooterData  $footer
     * @param  list<string>  $lots
     */
    public function __construct(
        public string $referenceNumber,
        public string $shipmentId,
        public array $pages,
        public array $footer,
        public array $lots,
        public int $serialCount,
        public ?string $logoDataUri = null,
        public string $sellerDisplayName = 'Seller',
    ) {}
}
