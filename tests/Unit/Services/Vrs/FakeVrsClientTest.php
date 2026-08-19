<?php

namespace Tests\Unit\Services\Vrs;

use App\Services\Vrs\Contracts\VrsClient;
use App\Services\Vrs\FakeVrsClient;
use App\Services\Vrs\HttpVrsClient;
use App\Services\Vrs\NullVrsClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FakeVrsClientTest extends TestCase
{
    #[Test]
    public function non_production_defaults_vrs_driver_to_fake(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('fake', config('vrs.driver'));
        $this->assertInstanceOf(FakeVrsClient::class, app(VrsClient::class));
    }

    #[Test]
    public function production_defaults_vrs_driver_to_null_when_unset(): void
    {
        $previousAppEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV');
        $hadVrsDriver = array_key_exists('VRS_DRIVER', $_ENV) || getenv('VRS_DRIVER') !== false;
        $previousVrsDriver = $_ENV['VRS_DRIVER'] ?? getenv('VRS_DRIVER');

        try {
            putenv('APP_ENV=production');
            $_ENV['APP_ENV'] = 'production';
            $_SERVER['APP_ENV'] = 'production';
            putenv('VRS_DRIVER');
            unset($_ENV['VRS_DRIVER'], $_SERVER['VRS_DRIVER']);

            $config = require base_path('config/vrs.php');
            $this->assertSame('null', $config['driver']);
        } finally {
            if (is_string($previousAppEnv)) {
                putenv('APP_ENV='.$previousAppEnv);
                $_ENV['APP_ENV'] = $previousAppEnv;
                $_SERVER['APP_ENV'] = $previousAppEnv;
            }

            if ($hadVrsDriver && is_string($previousVrsDriver)) {
                putenv('VRS_DRIVER='.$previousVrsDriver);
                $_ENV['VRS_DRIVER'] = $previousVrsDriver;
                $_SERVER['VRS_DRIVER'] = $previousVrsDriver;
            } else {
                putenv('VRS_DRIVER');
                unset($_ENV['VRS_DRIVER'], $_SERVER['VRS_DRIVER']);
            }
        }
    }

    #[Test]
    public function null_driver_resolves_null_client(): void
    {
        config(['vrs.driver' => 'null']);
        app()->forgetInstance(VrsClient::class);

        $this->assertInstanceOf(NullVrsClient::class, app(VrsClient::class));
    }

    #[Test]
    public function fake_client_verifies_by_default(): void
    {
        config(['vrs.driver' => 'fake']);

        $result = app(FakeVrsClient::class)->verify('00301164024167', 'GOOD123');

        $this->assertSame('verified', $result['status']);
        $this->assertSame('00301164024167', $result['gtin14']);
        $this->assertSame('GOOD123', $result['serial']);
    }

    #[Test]
    public function fake_client_fails_serials_prefixed_with_fail(): void
    {
        $result = app(FakeVrsClient::class)->verify('00301164024167', 'FAIL-001');

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('do not match', $result['message']);
    }

    #[Test]
    public function fake_client_marks_network_serials_as_suspect(): void
    {
        $result = app(FakeVrsClient::class)->verify('00301164024167', 'NETWORK-001');

        $this->assertSame('suspect', $result['status']);
    }

    #[Test]
    public function http_client_maps_verified_response(): void
    {
        config([
            'vrs.http.base_url' => 'https://vrs.test',
            'vrs.http.verify_path' => '/verify',
            'vrs.http.api_key' => 'secret',
        ]);

        Http::fake([
            'https://vrs.test/verify' => Http::response([
                'verified' => true,
                'message' => 'OK from VRS',
            ], 200),
        ]);

        $result = app(HttpVrsClient::class)->verify('00301164024167', 'SN1', 'LOT-A');

        $this->assertSame('verified', $result['status']);
        $this->assertSame('LOT-A', $result['lot']);
        $this->assertSame('OK from VRS', $result['message']);
    }

    #[Test]
    public function http_client_maps_request_errors_to_error_status(): void
    {
        config([
            'vrs.http.base_url' => 'https://vrs.test',
            'vrs.http.verify_path' => '/verify',
        ]);

        Http::fake([
            'https://vrs.test/verify' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $result = app(HttpVrsClient::class)->verify('00301164024167', 'SN1');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('VRS request failed', $result['message']);
    }
}
