<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Atp;

use App\Services\Atp\FakeOciWalletClient;
use App\Services\Atp\OciVerifyResult;
use App\Services\Atp\OciWalletClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OciWalletClientTest extends TestCase
{
    #[Test]
    public function fake_client_returns_programmed_verify_outcomes(): void
    {
        $fake = (new FakeOciWalletClient)->willVerifyAs('valid');
        $this->assertTrue($fake->verify('eyJ.abc.sig')->isAcceptable());

        $expired = (new FakeOciWalletClient)->willVerifyAs('expired');
        $this->assertSame('expired', $expired->verify('eyJ.abc.sig')->status);

        $invalid = (new FakeOciWalletClient)->willVerifyAs('invalid');
        $this->assertSame('invalid', $invalid->verify('eyJ.abc.sig')->status);

        $this->assertSame('missing', (new FakeOciWalletClient)->verify('')->status);
    }

    #[Test]
    public function fake_client_present_returns_programmed_vp(): void
    {
        $fake = (new FakeOciWalletClient)->willPresent('compact-vp-token');

        $this->assertSame('compact-vp-token', $fake->present('0300001000001'));
    }

    #[Test]
    public function http_client_maps_verify_response(): void
    {
        config([
            'atp_oci.base_url' => 'https://wallet.tracepharma.test',
            'atp_oci.api_key' => 'wallet-key',
            'atp_oci.verify_path' => '/api/v1/verify-vp',
            'atp_oci.present_path' => '/api/v1/generate-vp',
            'atp_oci.timeout' => 5,
        ]);

        Http::fake([
            'https://wallet.tracepharma.test/api/v1/verify-vp' => Http::response([
                'valid' => true,
                'issuer' => 'did:web:issuer.example',
                'subject_gln' => '0300001000001',
                'expires_at' => now()->addDay()->toIso8601String(),
            ], 200),
        ]);

        $client = OciWalletClient::fromTenantSettings(null);
        $result = $client->verify('eyJhbGciOiJub25lIn0.e30.sig');

        $this->assertTrue($result->isAcceptable());
        $this->assertSame('verified', $result->status);
        $this->assertSame('did:web:issuer.example', $result->issuer);
        $this->assertSame('0300001000001', $result->subjectGln);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://wallet.tracepharma.test/api/v1/verify-vp'
                && ($request['vp'] ?? null) === 'eyJhbGciOiJub25lIn0.e30.sig';
        });
    }

    #[Test]
    public function http_client_present_returns_vp_string(): void
    {
        config([
            'atp_oci.base_url' => 'https://wallet.tracepharma.test',
            'atp_oci.api_key' => 'wallet-key',
            'atp_oci.verify_path' => '/api/v1/verify-vp',
            'atp_oci.present_path' => '/api/v1/generate-vp',
            'atp_oci.timeout' => 5,
        ]);

        Http::fake([
            'https://wallet.tracepharma.test/api/v1/generate-vp' => Http::response([
                'vp' => 'presented-compact-vp',
            ], 200),
        ]);

        $client = OciWalletClient::fromTenantSettings(null);

        $this->assertSame('presented-compact-vp', $client->present('0300001000001'));
    }

    #[Test]
    public function verify_result_maps_invalid_and_expired_reasons(): void
    {
        $invalid = OciVerifyResult::fromWalletResponse([
            'valid' => false,
            'reason' => 'Signature invalid',
        ]);
        $this->assertSame('invalid', $invalid->status);

        $expired = OciVerifyResult::fromWalletResponse([
            'valid' => false,
            'reason' => 'Credential expired',
        ]);
        $this->assertSame('expired', $expired->status);
    }
}
