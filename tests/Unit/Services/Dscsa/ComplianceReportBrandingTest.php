<?php

namespace Tests\Unit\Services\Dscsa;

use App\Services\Dscsa\Support\ComplianceReportBranding;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ComplianceReportBrandingTest extends TestCase
{
    #[Test]
    public function it_uses_tracepharma_logo_when_no_seller_gln_is_provided(): void
    {
        $branding = app(ComplianceReportBranding::class)->resolve(null, 'Seller');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', (string) $branding['logoDataUri']);
        $this->assertSame('Seller', $branding['sellerDisplayName']);
    }
}
