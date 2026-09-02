<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\MemoryLimit;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemoryLimitTest extends TestCase
{
    #[Test]
    public function it_parses_suffixed_memory_limits(): void
    {
        $this->assertSame(128 * 1024 * 1024, MemoryLimit::toBytes('128M'));
        $this->assertSame(5 * 1024 * 1024 * 1024, MemoryLimit::toBytes('5G'));
    }

    #[Test]
    public function restore_skips_when_current_usage_exceeds_target(): void
    {
        $previous = MemoryLimit::raise('256M');

        $holder = str_repeat('x', 2 * 1024 * 1024);

        MemoryLimit::restore('1M');

        $this->assertSame('256M', ini_get('memory_limit'));

        unset($holder);
        MemoryLimit::restore($previous);
    }
}
