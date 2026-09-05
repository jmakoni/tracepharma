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

    #[Test]
    public function resolve_safe_addresses_returns_literal_public_ip(): void
    {
        $addresses = EpcisSubscriptionUrl::resolveSafeAddresses('https://8.8.8.8/hooks/epcis');

        $this->assertSame(['8.8.8.8'], $addresses);
    }

    #[Test]
    public function resolve_safe_addresses_rejects_literal_private_ip(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EpcisSubscriptionUrl::resolveSafeAddresses('https://10.0.0.1/hooks/epcis');
    }

    #[Test]
    public function pinned_curl_options_pin_hostname_to_vetted_ips(): void
    {
        $options = EpcisSubscriptionUrl::pinnedCurlOptions(
            'https://hooks.partner.example/epcis',
            ['203.0.113.10', '2001:db8::1'],
        );

        $this->assertSame(
            ['hooks.partner.example:443:203.0.113.10,[2001:db8::1]'],
            $options['curl'][CURLOPT_RESOLVE],
        );
    }

    #[Test]
    public function pinned_curl_options_use_explicit_port(): void
    {
        $options = EpcisSubscriptionUrl::pinnedCurlOptions(
            'https://hooks.partner.example:8443/epcis',
            ['203.0.113.10'],
        );

        $this->assertSame(
            ['hooks.partner.example:8443:203.0.113.10'],
            $options['curl'][CURLOPT_RESOLVE],
        );
    }

    #[Test]
    public function pinned_curl_options_empty_for_literal_ip_url(): void
    {
        $options = EpcisSubscriptionUrl::pinnedCurlOptions('https://8.8.8.8/epcis', ['8.8.8.8']);

        $this->assertSame([], $options);
    }
}
