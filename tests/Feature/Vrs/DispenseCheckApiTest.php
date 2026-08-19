<?php

namespace Tests\Feature\Vrs;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\Epc;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Verification;
use App\Services\Quarantine\QuarantineService;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\SanctumAbilities;
use App\Support\TenantSettings;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DispenseCheckApiTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $verificationIds = [];

    /** @var list<int> */
    private array $exceptionIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    #[Test]
    public function dispense_check_omits_exception_id_when_caller_lacks_site_access_to_case(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);

            $siteA = Site::query()->create([
                'name' => 'Dispense Site A',
                'gln' => '0399991000015',
                'is_active' => true,
            ]);
            $siteB = Site::query()->create([
                'name' => 'Dispense Site B',
                'gln' => '0399991000022',
                'is_active' => true,
            ]);
            $this->siteIds = [(int) $siteA->getKey(), (int) $siteB->getKey()];

            $uri = 'urn:epc:id:sgtin:030116.3400516.SITE-GATE';
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $case = app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [(int) $epc->getKey()],
                reason: 'Site-restricted dispense gate',
            );
            $case->forceFill(['site_id' => $siteB->getKey()])->save();
            $this->exceptionIds[] = (int) $case->getKey();

            $user = User::factory()->create();
            $user->sites()->sync([(int) $siteA->getKey()]);
            $token = $user->createToken('dispense-test', [SanctumAbilities::VRS_DISPENSE_CHECK])->plainTextToken;

            tenancy()->end();

            $response = $this->tenantApiPost('/api/v1/dispense-check', $token, [
                'gtin14' => '30301164005162',
                'serial' => 'SITE-GATE',
            ]);

            $response->assertOk()
                ->assertJson([
                    'allowed' => false,
                    'status' => 'quarantined',
                ])
                ->assertJsonMissing(['exception_id' => $case->getKey()]);

            $this->assertArrayNotHasKey('exception_id', $response->json());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function dispense_check_returns_allowed_for_verified_product(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);

            $user = User::factory()->create();
            $token = $user->createToken('dispense-test', [SanctumAbilities::VRS_DISPENSE_CHECK])->plainTextToken;

            tenancy()->end();

            $response = $this->tenantApiPost('/api/v1/dispense-check', $token, [
                'gtin14' => '30301164005162',
                'serial' => 'GOOD123',
            ]);

            $response->assertOk()
                ->assertJson([
                    'allowed' => true,
                    'status' => 'verified',
                ])
                ->assertJsonStructure([
                    'allowed',
                    'status',
                    'message',
                    'verification_id',
                    'exception_id',
                ]);

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $verification = Verification::query()->find($response->json('verification_id'));
            $this->assertNotNull($verification);
            $this->verificationIds[] = (int) $verification->getKey();
            $this->assertSame($user->getKey(), $verification->verified_by);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function dispense_check_blocks_quarantined_epc_even_when_vrs_would_verify(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);

            $uri = 'urn:epc:id:sgtin:030116.3400516.GOOD123';
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();

            $case = app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [(int) $epc->getKey()],
                reason: 'Open hold blocks dispense',
            );
            $this->exceptionIds[] = (int) $case->getKey();

            $user = User::factory()->create();
            $token = $user->createToken('dispense-test', [SanctumAbilities::VRS_DISPENSE_CHECK])->plainTextToken;

            tenancy()->end();

            $response = $this->tenantApiPost('/api/v1/dispense-check', $token, [
                'gtin14' => '30301164005162',
                'serial' => 'GOOD123',
            ]);

            $response->assertOk()
                ->assertJson([
                    'allowed' => false,
                    'status' => 'quarantined',
                ])
                ->assertJsonPath('exception_id', $case->getKey());

            $message = (string) $response->json('message');
            $this->assertStringContainsString('quarantine', strtolower($message));
            $this->assertStringContainsString('exception #'.$case->getKey(), $message);

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $verification = Verification::query()->find($response->json('verification_id'));
            $this->assertNotNull($verification);
            $this->verificationIds[] = (int) $verification->getKey();
            $this->assertSame('quarantined', $verification->status);
            $this->assertSame((int) $case->getKey(), (int) $verification->exception_id);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function dispense_check_blocks_failed_verification(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);

            $user = User::factory()->create();
            $token = $user->createToken('dispense-test', [SanctumAbilities::VRS_DISPENSE_CHECK])->plainTextToken;

            tenancy()->end();

            $response = $this->tenantApiPost('/api/v1/dispense-check', $token, [
                'barcode' => '(01)30301164005162(21)FAIL-001',
            ]);

            $response->assertOk()
                ->assertJson([
                    'allowed' => false,
                    'status' => 'failed',
                ]);

            $this->assertNotNull($response->json('exception_id'));

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $this->verificationIds[] = (int) $response->json('verification_id');
            $this->exceptionIds[] = (int) $response->json('exception_id');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function dispense_check_requires_vrs_dispense_check_ability(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);

            $user = User::factory()->create();
            $token = $user->createToken('dispense-test', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;

            tenancy()->end();

            $this->tenantApiPost('/api/v1/dispense-check', $token, [
                'gtin14' => '30301164005162',
                'serial' => 'GOOD123',
            ])->assertForbidden();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function dispense_check_requires_authentication(): void
    {
        $this->initializeDemo2Tenant();

        try {
            tenancy()->end();

            $this->tenantApiPost('/api/v1/dispense-check', null, [
                'gtin14' => '30301164005162',
                'serial' => 'GOOD123',
            ])->assertUnauthorized();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function dispense_check_requires_nav_verify_when_job_roles_are_enabled(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
            $tenant->save();

            config(['vrs.driver' => 'fake']);

            $user = User::factory()->create();
            $user->syncRoles([TenantRole::ReceivingTechnician->value]);
            $token = $user->createToken('dispense-test', [SanctumAbilities::VRS_DISPENSE_CHECK])->plainTextToken;

            tenancy()->end();

            $this->tenantApiPost('/api/v1/dispense-check', $token, [
                'gtin14' => '30301164005162',
                'serial' => 'GOOD123',
            ])->assertForbidden();
        } finally {
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->save();
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

        if ($this->verificationIds !== []) {
            Verification::query()->whereKey($this->verificationIds)->delete();
            $this->verificationIds = [];
        }

        if ($this->exceptionIds !== []) {
            QuarantineHold::query()->whereIn('exception_id', $this->exceptionIds)->delete();
            ExceptionCase::query()->whereKey($this->exceptionIds)->delete();
            $this->exceptionIds = [];
        }

        if ($this->epcIds !== []) {
            QuarantineHold::query()->whereIn('epc_id', $this->epcIds)->delete();
            Epc::query()->whereKey($this->epcIds)->delete();
            $this->epcIds = [];
        }

        if ($this->siteIds !== []) {
            Site::query()->whereKey($this->siteIds)->delete();
            $this->siteIds = [];
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
