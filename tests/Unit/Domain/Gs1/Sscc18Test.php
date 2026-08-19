<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Gs1;

use App\Domain\Gs1\Sscc18;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class Sscc18Test extends TestCase
{
    #[Test]
    public function it_accepts_valid_sscc18(): void
    {
        $sscc = Sscc18::fromDigits('003011610012354038');

        $this->assertSame('003011610012354038', $sscc->toString());
        $this->assertSame('0', $sscc->extensionDigit());
    }

    #[Test]
    public function it_builds_from_company_prefix_and_serial_ref(): void
    {
        $sscc = Sscc18::fromCompanyPrefixAndSerialRef('030116', '0', '1001235403');

        $this->assertSame('003011610012354038', $sscc->toString());
    }

    #[Test]
    public function it_rejects_bad_check_digit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Sscc18::fromDigits('003011610012354037');
    }
}
