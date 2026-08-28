<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Epcis;

use App\Support\Epcis\EpcisSubscriptionUrl;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisSubscriptionUrlTest extends TestCase
{
    #[Test]
    public function accepts_public_https_hostname(): void
    {
        EpcisSubscriptionUrl::assertSafeTargetUrl('https://partner.example.com/hooks/epcis');
        $this->assertTrue(true);
    }

    #[Test]
    public function rejects_http_scheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EpcisSubscriptionUrl::assertSafeTargetUrl('http://partner.example.com/hooks/epcis');
    }

    #[Test]
    public function rejects_loopback_and_private_ips(): void
    {
        $this->assertTrue(EpcisSubscriptionUrl::isDeniedResolvedAddress('127.0.0.1'));
        $this->assertTrue(EpcisSubscriptionUrl::isDeniedResolvedAddress('10.0.0.1'));
        $this->assertTrue(EpcisSubscriptionUrl::isDeniedResolvedAddress('192.168.1.1'));
        $this->assertFalse(EpcisSubscriptionUrl::isDeniedResolvedAddress('8.8.8.8'));
    }
}
