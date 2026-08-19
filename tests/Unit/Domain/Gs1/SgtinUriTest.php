<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Gs1;

use App\Domain\Gs1\Gtin14;
use App\Domain\Gs1\SgtinUri;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SgtinUriTest extends TestCase
{
    #[Test]
    public function it_builds_sgtin_uri_from_gtin_and_serial(): void
    {
        $gtin = Gtin14::fromDigits('30301164005162');
        $uri = SgtinUri::fromGtinAndSerial($gtin, '10000002877732', '030116');

        $this->assertSame('urn:epc:id:sgtin:030116.3400516.10000002877732', $uri->toString());
        $this->assertSame('30301164005162', $uri->gtin()->toString());
    }

    #[Test]
    public function it_parses_sgtin_urn(): void
    {
        $uri = SgtinUri::fromUrn('urn:epc:id:sgtin:030116.3400516.10000002877732');

        $this->assertSame('030116', $uri->companyPrefix());
        $this->assertSame('10000002877732', $uri->serial());
        $this->assertSame('30301164005162', $uri->gtin()->toString());
    }

    #[Test]
    public function it_rejects_mismatched_company_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SgtinUri::fromGtinAndSerial(Gtin14::fromDigits('30301164005162'), '1', '999999');
    }

    #[Test]
    public function it_parses_mixed_case_scheme(): void
    {
        $uri = SgtinUri::fromUrn('URN:EPC:ID:SGTIN:030116.3400516.10000002877732');

        $this->assertSame('urn:epc:id:sgtin:030116.3400516.10000002877732', $uri->toString());
    }
}
