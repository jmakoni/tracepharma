<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Vrs;

use App\Models\Tenant;
use App\Services\Atp\FakeOciWalletClient;
use App\Services\Atp\OciWalletClient;
use App\Services\Vrs\HttpVrsClient;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HttpVrsClientAtpPresentTest extends TestCase
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
    public function present_outbound_attaches_atp_authorization_header(): void
    {
        Http::fake([
            'https://vrs.test/verify' => Http::response([
                'verified' => true,
                'message' => 'ok',
            ], 200),
        ]);

        $tenant = Tenant::query()->first();
        if ($tenant === null) {
            $this->markTestSkipped('No tenant available for outbound present test.');
        }

        tenancy()->initialize($tenant);
        TenantSettings::forTenant($tenant)->setAtpOciPresentOutbound(true);
        $tenant->save();

        $fake = (new FakeOciWalletClient)->willPresent('compact-outbound-vp');
        $this->app->instance(OciWalletClient::class, $fake);

        try {
            $result = app(HttpVrsClient::class)->verify('00301164024167', 'SN1');

            $this->assertSame('verified', $result['status']);

            Http::assertSent(function ($request): bool {
                $header = $request->header('ATP-Authorization')[0]
                    ?? $request->header('ATP-Authorization')[0]
                    ?? null;

                return $header === 'Bearer compact-outbound-vp';
            });
        } finally {
            TenantSettings::forTenant($tenant)->setAtpOciPresentOutbound(false);
            $tenant->save();
            tenancy()->end();
        }
    }
}
