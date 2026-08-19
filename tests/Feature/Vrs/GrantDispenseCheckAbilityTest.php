<?php

declare(strict_types=1);

namespace Tests\Feature\Vrs;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Verification;
use App\Support\SanctumAbilities;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GrantDispenseCheckAbilityTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $tokenIds = [];

    /** @var list<int> */
    private array $verificationIds = [];

    #[Test]
    public function grant_command_appends_dispense_check_and_enables_dispense_check_api(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);

            $user = User::factory()->create();
            $issued = $user->createToken('legacy-pms', [SanctumAbilities::EPCIS_VIEW]);
            $token = $issued->plainTextToken;
            $tokenId = (int) $issued->accessToken->getKey();
            $this->tokenIds[] = $tokenId;

            tenancy()->end();

            $this->tenantApiPost('/api/v1/dispense-check', $token, [
                'gtin14' => '30301164005162',
                'serial' => 'GOOD123',
            ])->assertForbidden();

            $this->artisan('tracepharma:grant-dispense-check-ability', [
                '--tenant' => [self::DEMO2_TENANT_ID],
            ])->assertSuccessful();

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $stored = PersonalAccessToken::query()->find($tokenId);
            $this->assertNotNull($stored);
            $this->assertContains(SanctumAbilities::VRS_DISPENSE_CHECK, $stored->abilities);
            $this->assertContains(SanctumAbilities::EPCIS_VIEW, $stored->abilities);
            $this->assertTrue(PersonalAccessToken::findToken($token)->can(SanctumAbilities::VRS_DISPENSE_CHECK));
            tenancy()->end();

            auth()->forgetGuards();

            $response = $this->tenantApiPost('/api/v1/dispense-check', $token, [
                'gtin14' => '30301164005162',
                'serial' => 'GOOD123',
            ])->assertOk()
                ->assertJson([
                    'allowed' => true,
                    'status' => 'verified',
                ]);

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $this->verificationIds[] = (int) $response->json('verification_id');
            tenancy()->end();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function grant_command_dry_run_does_not_update_tokens(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::factory()->create();
            $user->createToken('dry-run', [SanctumAbilities::EPCIS_UPLOAD]);
            $tokenId = (int) PersonalAccessToken::query()->latest('id')->value('id');
            $this->tokenIds[] = $tokenId;

            $this->artisan('tracepharma:grant-dispense-check-ability', [
                '--tenant' => [self::DEMO2_TENANT_ID],
                '--dry-run' => true,
            ])->assertSuccessful();

            $stored = PersonalAccessToken::query()->find($tokenId);
            $this->assertNotNull($stored);
            $this->assertSame([SanctumAbilities::EPCIS_UPLOAD], $stored->abilities);
        } finally {
            $this->cleanup();
        }
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
        }

        if ($this->tokenIds !== []) {
            PersonalAccessToken::query()->whereKey($this->tokenIds)->delete();
            $this->tokenIds = [];
        }

        if ($this->verificationIds !== []) {
            Verification::query()->whereKey($this->verificationIds)->delete();
            $this->verificationIds = [];
        }

        tenancy()->end();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function tenantApiPost(string $uri, ?string $token, array $data = []): \Illuminate\Testing\TestResponse
    {
        $path = str_starts_with($uri, '/') ? $uri : '/'.$uri;
        $absolute = 'http://'.self::DEMO2_DOMAIN.$path;

        $server = [
            'HTTP_HOST' => self::DEMO2_DOMAIN,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call(
            'POST',
            $absolute,
            [],
            [],
            [],
            $server,
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }
}
