<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Gs1;

use App\Domain\Gs1\CheckDigit;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CheckDigitTest extends TestCase
{
    #[Test]
    public function it_computes_known_mod10_vectors(): void
    {
        $this->assertSame('2', CheckDigit::mod10('0034374222661'));
        $this->assertSame('8', CheckDigit::mod10('00301161001235403'));
        $this->assertSame('2', CheckDigit::mod10('3030116400516'));
    }

    #[Test]
    public function it_rejects_empty_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CheckDigit::mod10('');
    }
}
