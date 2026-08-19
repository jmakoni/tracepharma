<?php

namespace Tests\Unit\Services\Vrs;

use App\Exceptions\VrsConfigurationException;
use App\Services\Vrs\HttpVrsClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HttpVrsClientTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'vrs.http.base_url' => 'https://vrs.test',
            'vrs.http.verify_path' => '/verify',
            'vrs.http.api_key' => null,
        ]);
    }

    #[Test]
    public function unreachable_endpoint_returns_unavailable_instead_of_throwing(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('cURL error 6: Could not resolve host: vrs.test');
        });

        $result = app(HttpVrsClient::class)->verify('00301164024167', 'SN1', 'LOT-A', '260731');

        $this->assertSame('unavailable', $result['status']);
        $this->assertStringContainsString('VRS unreachable', $result['message']);
        $this->assertSame('00301164024167', $result['gtin14']);
        $this->assertSame('SN1', $result['serial']);
        $this->assertSame('LOT-A', $result['lot']);
        $this->assertSame('260731', $result['expiry_yymmdd']);
    }

    #[Test]
    public function server_faults_return_error_status(): void
    {
        Http::fake([
            'https://vrs.test/verify' => Http::response(['message' => 'Boom'], 500),
        ]);

        $result = app(HttpVrsClient::class)->verify('00301164024167', 'SN1');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('VRS request failed', $result['message']);
    }

    #[Test]
    public function unexpected_failures_still_return_a_persistable_result(): void
    {
        Http::fake(function (): never {
            throw new \RuntimeException('Too many redirects');
        });

        $result = app(HttpVrsClient::class)->verify('00301164024167', 'SN1');

        $this->assertSame('error', $result['status']);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('gtin14', $result);
    }

    #[Test]
    public function transport_messages_fit_the_verifications_message_column(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException(str_repeat('cURL error 28: operation timed out. ', 60));
        });

        $result = app(HttpVrsClient::class)->verify('00301164024167', 'SN1');

        $this->assertLessThanOrEqual(512, mb_strlen($result['message']));
    }

    #[Test]
    public function placeholder_base_url_is_rejected(): void
    {
        config(['vrs.http.base_url' => 'https://vrs.example.com']);

        $this->expectException(VrsConfigurationException::class);
        $this->expectExceptionMessage('vrs.example.com');

        app(HttpVrsClient::class);
    }

    #[Test]
    public function shipped_config_default_would_be_rejected_for_the_http_driver(): void
    {
        $default = require base_path('config/vrs.php');

        config(['vrs.http.base_url' => $default['http']['base_url']]);

        $this->expectException(VrsConfigurationException::class);

        HttpVrsClient::assertConfigured();
    }

    #[Test]
    public function empty_base_url_is_rejected(): void
    {
        config(['vrs.http.base_url' => '']);

        $this->expectException(VrsConfigurationException::class);
        $this->expectExceptionMessage('VRS_BASE_URL must be set');

        app(HttpVrsClient::class);
    }

    #[Test]
    public function non_absolute_base_url_is_rejected(): void
    {
        config(['vrs.http.base_url' => 'vrs-internal/api']);

        $this->expectException(VrsConfigurationException::class);
        $this->expectExceptionMessage('absolute http(s) URL');

        app(HttpVrsClient::class);
    }

    #[Test]
    public function a_real_endpoint_is_accepted(): void
    {
        config(['vrs.http.base_url' => 'https://vrs.partner-network.com']);

        HttpVrsClient::assertConfigured();

        $this->assertInstanceOf(HttpVrsClient::class, app(HttpVrsClient::class));
    }
}
