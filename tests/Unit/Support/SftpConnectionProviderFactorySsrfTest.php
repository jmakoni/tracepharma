<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\OutboundConnection;
use App\Support\SftpConnectionProviderFactory;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
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
    public function rejects_ipv4_unspecified_host(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/loopback|link-local|metadata/i');

        SftpConnectionProviderFactory::assertSafeHost('0.0.0.0');
    }

    #[Test]
    public function rejects_ipv6_unspecified_host(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/loopback|link-local|metadata/i');

        SftpConnectionProviderFactory::assertSafeHost('::');
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
        $addresses = SftpConnectionProviderFactory::assertSafeHost('10.0.0.55');

        $this->assertSame(['10.0.0.55'], $addresses);
    }

    #[Test]
    public function allows_public_host_literal(): void
    {
        $addresses = SftpConnectionProviderFactory::assertSafeHost('8.8.8.8');

        $this->assertSame(['8.8.8.8'], $addresses);
    }

    #[Test]
    public function rejects_unresolvable_hostname(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/could not be resolved/i');

        SftpConnectionProviderFactory::assertSafeHost('definitely-not-a-real-host.invalid');
    }

    #[Test]
    public function pins_provider_host_to_resolved_safe_address(): void
    {
        $fingerprint = 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99';

        $provider = SftpConnectionProviderFactory::forOutboundConnection(new OutboundConnection([
            'settings' => [
                'host' => '8.8.8.8',
                'port' => 22,
                'host_fingerprint' => $fingerprint,
            ],
            'credentials' => ['username' => 'shipper', 'password' => 'secret'],
        ]));

        $hostProperty = new ReflectionProperty($provider, 'host');
        $this->assertSame('8.8.8.8', $hostProperty->getValue($provider));
    }

    #[Test]
    public function brackets_ipv6_when_pinning_provider_host(): void
    {
        $fingerprint = 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99';

        $provider = SftpConnectionProviderFactory::forOutboundConnection(new OutboundConnection([
            'settings' => [
                'host' => '2001:db8::1',
                'port' => 22,
                'host_fingerprint' => $fingerprint,
            ],
            'credentials' => ['username' => 'shipper', 'password' => 'secret'],
        ]));

        $hostProperty = new ReflectionProperty($provider, 'host');
        $this->assertSame('[2001:db8::1]', $hostProperty->getValue($provider));
    }

    #[Test]
    public function fsockopen_host_brackets_ipv6_only(): void
    {
        $this->assertSame('10.0.0.55', SftpConnectionProviderFactory::fsockopenHost('10.0.0.55'));
        $this->assertSame('[2001:db8::1]', SftpConnectionProviderFactory::fsockopenHost('2001:db8::1'));
    }

    #[Test]
    public function requires_host_fingerprint_before_constructing_provider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/host_fingerprint/i');

        SftpConnectionProviderFactory::forOutboundConnection(new OutboundConnection([
            'settings' => ['host' => '8.8.8.8', 'port' => 22],
            'credentials' => ['username' => 'shipper', 'password' => 'secret'],
        ]));
    }

    #[Test]
    public function passes_host_fingerprint_into_provider(): void
    {
        $fingerprint = 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99';

        $provider = SftpConnectionProviderFactory::forOutboundConnection(new OutboundConnection([
            'settings' => [
                'host' => '8.8.8.8',
                'port' => 22,
                'host_fingerprint' => $fingerprint,
            ],
            'credentials' => ['username' => 'shipper', 'password' => 'secret'],
        ]));

        $this->assertInstanceOf(SftpConnectionProvider::class, $provider);

        $property = new ReflectionProperty($provider, 'hostFingerprint');
        $this->assertSame($fingerprint, $property->getValue($provider));
    }
}
