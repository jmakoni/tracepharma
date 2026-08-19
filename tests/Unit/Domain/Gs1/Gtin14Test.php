<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Gs1;

use App\Domain\Gs1\Gtin14;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class Gtin14Test extends TestCase
{
    #[Test]
    public function it_accepts_valid_gtin14_and_upc(): void
    {
        $this->assertSame('00343742226612', Gtin14::fromDigits('00343742226612')->toString());
        $this->assertSame('00343742226612', Gtin14::fromDigits('343742226612')->toString());
        $this->assertSame('00343742226612', Gtin14::fromPackageNdc('43742-2266-1')->toString());
    }

    #[Test]
    public function it_rejects_invalid_check_digit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Gtin14::fromDigits('00343742226610');
    }

    #[Test]
    public function it_rejects_unsupported_lengths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Gtin14::fromDigits('34374222661');
    }
}
