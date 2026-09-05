<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Gs1;

use App\Support\Gs1\Gtin;
use App\Support\Gs1\Sgln;
use App\Support\Gs1\SglnResolution;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GlnSglnIdentityConsistencyTest extends TestCase
{
    #[Test]
    public function resolve_urn_rejects_gln_with_wrong_check_digit_against_matching_body_sgln(): void
    {
        $body12 = '036615900012';
        $correctGln = $body12.Gtin::checkDigit($body12);
        $wrongGln = $body12.'3';
        $this->assertNotSame($correctGln, $wrongGln);

        $sgln = 'urn:epc:id:sgln:036615.900012.0';
        $this->assertSame($correctGln, Sgln::fromUrn($sgln)['gln']);
        $this->assertNull(Sgln::resolveUrn($wrongGln, null, [$sgln]));
        $this->assertSame($sgln, Sgln::resolveUrn($correctGln, null, [$sgln]));
    }

    #[Test]
    public function from_prefix_length_refuses_gln_with_invalid_check_digit(): void
    {
        $body12 = '036615900012';
        $correctGln = $body12.Gtin::checkDigit($body12);
        $wrongGln = $body12.'3';

        $fromCorrect = SglnResolution::fromPrefixLength($correctGln, '039991');
        $this->assertNotNull($fromCorrect);
        $this->assertSame($correctGln, Sgln::fromUrn((string) $fromCorrect)['gln']);

        $this->assertNull(SglnResolution::fromPrefixLength($wrongGln, '039991'));
        $this->assertNull(SglnResolution::fromCompanyPrefix($wrongGln, '036615'));
    }
}
