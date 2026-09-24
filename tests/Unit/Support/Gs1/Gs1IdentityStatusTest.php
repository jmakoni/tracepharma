<?php

namespace Tests\Unit\Support\Gs1;

use App\Support\Gs1\Gs1IdentityStatus;
use App\Support\Gs1\SglnResolution;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Gs1IdentityStatusTest extends TestCase
{
    #[Test]
    public function company_sgln_preview_shows_urn_or_refuse_copy(): void
    {
        $this->assertSame(
            SglnResolution::fromCompanyPrefix('0366159000026', '036615'),
            Gs1IdentityStatus::companySglnPreview('0366159000026', '036615'),
        );
        $this->assertSame(
            Gs1IdentityStatus::MISSING_COMPANY_SGLN,
            Gs1IdentityStatus::companySglnPreview('0366159000026', null),
        );
        $this->assertSame(
            Gs1IdentityStatus::MISSING_COMPANY_SGLN,
            Gs1IdentityStatus::companySglnPreview(null, '036615'),
        );
    }

    #[Test]
    public function partner_sgln_status_distinguishes_missing_inbound_and_paste(): void
    {
        $gln = '0366159000026';
        $urn = SglnResolution::fromCompanyPrefix($gln, '036615');
        $this->assertNotNull($urn);

        $this->assertSame(
            Gs1IdentityStatus::PARTNER_MISSING,
            Gs1IdentityStatus::partnerSglnStatus(null, $gln),
        );
        $this->assertSame(
            Gs1IdentityStatus::PARTNER_FROM_EPCIS,
            Gs1IdentityStatus::partnerSglnStatus($urn, $gln, $urn),
        );
        $this->assertSame(
            Gs1IdentityStatus::PARTNER_PASTED,
            Gs1IdentityStatus::partnerSglnStatus($urn, $gln, null),
        );
    }
}
