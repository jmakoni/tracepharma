<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Gs1;

use App\Domain\Gs1\Sscc18;
use App\Domain\Gs1\SsccUri;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SsccUriTest extends TestCase
{
    #[Test]
    public function it_builds_sscc_uri_from_sscc18(): void
    {
        $sscc = Sscc18::fromDigits('003011610012354038');
        $uri = SsccUri::fromSscc($sscc, '030116');

        $this->assertSame('urn:epc:id:sscc:030116.01001235403', $uri->toString());
    }

    #[Test]
    public function it_parses_sscc_urn(): void
    {
        $uri = SsccUri::fromUrn('urn:epc:id:sscc:030116.01001235403');

        $this->assertSame('030116', $uri->companyPrefix());
        $this->assertSame('003011610012354038', $uri->sscc()->toString());
    }

    #[Test]
    public function it_rejects_invalid_urn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SsccUri::fromUrn('urn:epc:id:sgtin:030116.3400516.1');
    }
}
