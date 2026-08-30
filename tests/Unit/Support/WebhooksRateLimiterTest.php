<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WebhooksRateLimiterTest extends TestCase
{
    #[Test]
    public function webhooks_limiter_keys_differ_by_host(): void
    {
        $callback = RateLimiter::limiter('webhooks');
        $this->assertNotNull($callback);

        $limitA = $callback(Request::create('https://tenant-a.example.test/hook', 'POST'));
        $limitB = $callback(Request::create('https://tenant-b.example.test/hook', 'POST'));

        $this->assertInstanceOf(Limit::class, $limitA);
        $this->assertInstanceOf(Limit::class, $limitB);
        $this->assertNotSame($limitA->key, $limitB->key);
        $this->assertStringContainsString('tenant-a.example.test', (string) $limitA->key);
        $this->assertStringContainsString('tenant-b.example.test', (string) $limitB->key);
    }
}
