<?php

namespace Tests\Unit\Services\Labeling;

use App\Services\Labeling\SsccBuilder;
use App\Support\Gs1\Gtin;
use App\Support\Gs1\Sscc;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SsccBuilderTest extends TestCase
{
    #[Test]
    public function it_builds_sscc_matching_epc_examples(): void
    {
        $builder = new SsccBuilder;

        $result = $builder->build('030116', 210167, 0);

        $this->assertSame(18, strlen($result['sscc_18']));
        $this->assertSame('030116.00000210167', $result['sscc_dotted']);
        $this->assertSame('urn:epc:id:sscc:030116.00000210167', $result['sscc_urn']);
        $this->assertSame('0000210167', $result['serial_reference']);
        $this->assertSame('00'.$result['sscc_18'], $result['hrt']);
        $this->assertSame('00'.$result['sscc_18'], $result['element_string']);
        $this->assertSame(
            Gtin::checkDigit(substr($result['sscc_18'], 0, 17)),
            substr($result['sscc_18'], -1)
        );

        $fromHelper = Sscc::fromUrn($result['sscc_urn']);
        $this->assertNotNull($fromHelper);
        $this->assertSame($result['sscc_18'], $fromHelper['sscc18']);
    }

    #[Test]
    public function it_rejects_serial_reference_overflow_for_prefix_length(): void
    {
        $builder = new SsccBuilder;

        $this->expectException(\InvalidArgumentException::class);

        $builder->build('030116', 10_000_000_000, 0);
    }

    #[Test]
    public function it_rejects_invalid_company_prefix_length(): void
    {
        $builder = new SsccBuilder;

        $this->expectException(\InvalidArgumentException::class);

        $builder->build('12345', 1, 0);
    }
}
