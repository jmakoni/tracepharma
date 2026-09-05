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
    public function allows_rfc1918_on_prem_printer_host(): void
    {
        NetworkPrinterClient::assertSafePrinterHost('10.0.0.55');

        $this->assertTrue(true);
    }

    #[Test]
    public function rejects_unresolvable_hostname(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/could not be resolved/i');

        NetworkPrinterClient::assertSafePrinterHost('definitely-not-a-real-host.invalid');
    }
}
