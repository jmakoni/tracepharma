<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SftpConnectionProviderFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SftpConnectionProviderFactorySsrfTest extends TestCase
{
    #[Test]
    public function rejects_loopback_host(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/loopback|link-local|metadata/i');

        SftpConnectionProviderFactory::assertSafeHost('127.0.0.1');
    }

    #[Test]
    public function rejects_link_local_metadata_host(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/loopback|link-local|metadata/i');

        SftpConnectionProviderFactory::assertSafeHost('169.254.169.254');
    }

    #[Test]
    public function rejects_localhost_hostname(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SftpConnectionProviderFactory::assertSafeHost('localhost');
    }

    #[Test]
    public function allows_rfc1918_on_prem_sftp_host(): void
    {
        SftpConnectionProviderFactory::assertSafeHost('10.0.0.55');

        $this->assertTrue(true);
    }

    #[Test]
    public function allows_public_host_literal(): void
    {
        SftpConnectionProviderFactory::assertSafeHost('8.8.8.8');

        $this->assertTrue(true);
    }
}
