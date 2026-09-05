<?php

namespace Tests\Unit\Services\Vrs;

use App\Exceptions\VrsConfigurationException;
use App\Services\Vrs\FakeVrsClient;
use App\Services\Vrs\HttpVrsClient;
use App\Services\Vrs\NullVrsClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VrsExpiryPassthroughTest extends TestCase
{
    #[Test]
    public function http_client_sends_expiry_and_requestor_gln(): void
    {
        config([
            'vrs.http.base_url' => 'https://vrs.test',
            'vrs.http.verify_path' => '/verify',
            'vrs.http.requestor_gln' => '0614141000005',
        ]);

        Http::fake([
            'https://vrs.test/verify' => Http::response(['verified' => true], 200),
        ]);

        $result = app(HttpVrsClient::class)->verify('00301164024167', 'SN1', 'LOT-A', '260731');

        $this->assertSame('260731', $result['expiry_yymmdd']);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $body['gtin'] === '00301164024167'
                && $body['serial'] === 'SN1'
                && $body['lot'] === 'LOT-A'
                && $body['expiry'] === '260731'
                && $body['requestor_gln'] === '0614141000005';
        });
    }

    #[Test]
    public function http_client_omits_expiry_and_requestor_gln_when_unknown(): void
    {
        config([
            'vrs.http.base_url' => 'https://vrs.test',
            'vrs.http.verify_path' => '/verify',
            'vrs.http.requestor_gln' => null,
        ]);

        Http::fake([
            'https://vrs.test/verify' => Http::response(['verified' => true], 200),
        ]);

        $result = app(HttpVrsClient::class)->verify('00301164024167', 'SN1');

        $this->assertNull($result['expiry_yymmdd']);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ! array_key_exists('expiry', $body)
                && ! array_key_exists('requestor_gln', $body);
        });
    }

    #[Test]
    public function fake_client_echoes_the_expiry_back(): void
    {
        $fake = app(FakeVrsClient::class)->verify('00301164024167', 'GOOD1', 'LOT-A', '260731');
        $this->assertSame('260731', $fake['expiry_yymmdd']);
        $this->assertSame('verified', $fake['status']);
    }

    #[Test]
    public function null_client_throws_configuration_exception(): void
    {
        $this->expectException(VrsConfigurationException::class);
        $this->expectExceptionMessage('VRS_DRIVER');

        app(NullVrsClient::class)->verify('00301164024167', 'GOOD1', null, '260731');
    }
}
