<?php

namespace Tests\Unit\Support\Epcis;

use App\Support\Epcis\EpcisCacheLock;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisCacheLockTest extends TestCase
{
    #[Test]
    public function uses_default_cache_store_when_it_is_not_file(): void
    {
        config(['cache.default' => 'array']);

        $this->assertSame('array', EpcisCacheLock::storeName());
    }

    #[Test]
    public function never_uses_file_store_even_when_default_is_file(): void
    {
        config(['cache.default' => 'file']);

        $this->assertSame('redis', EpcisCacheLock::storeName());
        $this->assertNotSame('file', EpcisCacheLock::storeName());
    }
}
