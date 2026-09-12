<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Labeling;

use App\Services\Labeling\NetworkPrinterClient;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NetworkPrinterClientSsrfTest extends TestCase
{
    #[Test]
    public function rejects_link_local_metadata_host(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/loopback|link-local|metadata/i');

        NetworkPrinterClient::assertSafePrinterHost('169.254.169.254');
    }

    #[Test]
    public function rejects_loopback_host(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        NetworkPrinterClient::assertSafePrinterHost('127.0.0.1');
    }

    #[Test]
    public function rejects_ipv4_unspecified_host(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/loopback|link-local|metadata/i');

        NetworkPrinterClient::assertSafePrinterHost('0.0.0.0');
    }

    #[Test]
    public function rejects_ipv6_unspecified_host(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/loopback|link-local|metadata/i');

        NetworkPrinterClient::assertSafePrinterHost('::');
    }

    #[Test]
    public function allows_rfc1918_on_prem_printer_host(): void
    {
        $addresses = NetworkPrinterClient::assertSafePrinterHost('10.0.0.55');

        $this->assertSame(['10.0.0.55'], $addresses);
    }

    #[Test]
    public function rejects_unresolvable_hostname(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/could not be resolved/i');

        NetworkPrinterClient::assertSafePrinterHost('definitely-not-a-real-host.invalid');
    }

    #[Test]
    public function pins_fsockopen_host_to_literal_ipv4(): void
    {
        $this->assertSame('10.0.0.55', NetworkPrinterClient::fsockopenHost('10.0.0.55'));
    }

    #[Test]
    public function brackets_ipv6_for_fsockopen(): void
    {
        $this->assertSame('[2001:db8::1]', NetworkPrinterClient::fsockopenHost('2001:db8::1'));
    }
}
