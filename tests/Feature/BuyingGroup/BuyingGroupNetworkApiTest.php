<?php

declare(strict_types=1);

namespace Tests\Feature\BuyingGroup;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\BuyingGroupMember;
use App\Models\BuyingGroupMemberMetric;
use App\Models\BuyingGroupPartnerFact;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\SanctumAbilities;
use App\Support\TenantSettings;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BuyingGroupNetworkApiTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    /** @var list<int> */
    private array $memberIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<string> */
    private array $metricMemberTenantIds = [];

    private ?string $priorAffiliationCode = null;

    protected function tearDown(): void
    {
        $this->cleanupTenantRows();
        parent::tearDown();
    }

    #[Test]
    public function buying_group_token_lists_members_and_summary_without_epc_payloads(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::BuyingGroup);
        $this->priorAffiliationCode = TenantSettings::forTenant($tenant)->affiliationCode();
        TenantSettings::forTenant($tenant)->setAffiliationCode('GPO-API-1');
        $tenant->save();

        try {
            $soft = BuyingGroupMember::query()->create([
                'name' => 'API Soft Member',
                'status' => 'active',
                'affiliation_code' => 'AFF-1',
            ]);
            $this->memberIds[] = (int) $soft->getKey();

            $memberTenantId = (string) Str::uuid();
            $hard = BuyingGroupMember::query()->create([
                'name' => 'API Hard Member',
                'status' => 'active',
                'member_tenant_id' => $memberTenantId,
            ]);
            $this->memberIds[] = (int) $hard->getKey();

            BuyingGroupMemberMetric::query()->updateOrCreate(
                [
                    'member_tenant_id' => $memberTenantId,
                    'as_of' => now()->toDateString(),
                ],
                [
                    'atp_gap_count' => 2,
                    'exceptions_open' => 1,
                    'exceptions_aging_7d' => 0,
                    'connection_unhealthy' => false,
                    'last_epcis_success_at' => now()->subDay(),
                    'health_score' => 88.5,
                    'risk_score' => 12.0,
                ],
            );
            $this->metricMemberTenantIds[] = $memberTenantId;

            BuyingGroupPartnerFact::query()->updateOrCreate(
                [
                    'member_tenant_id' => $memberTenantId,
                    'partner_key' => 'partner-a',
                    'as_of' => now()->toDateString(),
                ],
                [
                    'partner_name' => 'Partner A Wholesaler',
                    'partner_gln' => '0366159000010',
                    'license_status' => 'valid',
                    'expires_at' => now()->addYear()->toDateString(),
                ],
            );

            $token = $this->createBuyingGroupToken();
            tenancy()->end();

            $members = $this->tenantApiGet('/api/v1/buying-group/members?per_page=50', $token);
            $members->assertOk()
                ->assertJsonPath('meta.total', 2)
                ->assertJsonFragment(['name' => 'API Soft Member', 'hard_linked' => false])
                ->assertJsonFragment(['name' => 'API Hard Member', 'hard_linked' => true]);

            $payload = json_encode($members->json());
            $this->assertIsString($payload);
            $this->assertStringNotContainsString('epc_list', $payload);
            $this->assertStringNotContainsString('urn:epc', $payload);

            $readiness = $this->tenantApiGet('/api/v1/buying-group/members/'.$hard->getKey().'/readiness', $token);
            $readiness->assertOk()
                ->assertJsonPath('data.readiness.live_metrics', true)
                ->assertJsonPath('data.readiness.atp_gap_count', 2);

            $softReadiness = $this->tenantApiGet('/api/v1/buying-group/members/'.$soft->getKey().'/readiness', $token);
            $softReadiness->assertOk()
                ->assertJsonPath('data.readiness.soft_only', true)
                ->assertJsonPath('data.readiness.live_metrics', false);

            $summary = $this->tenantApiGet('/api/v1/buying-group/network/summary', $token);
            $summary->assertOk()
                ->assertJsonPath('data.affiliation_code', 'GPO-API-1')
                ->assertJsonPath('data.enrollment.hard_linked', 1)
                ->assertJsonPath('data.enrollment.soft_linked', 1)
                ->assertJsonPath('data.health.members_with_live_metrics', 1)
                ->assertJsonPath('data.health.atp_gap_total', 2);

            $matrix = $this->tenantApiGet('/api/v1/buying-group/partner-matrix?per_page=10', $token);
            $matrix->assertOk()
                ->assertJsonFragment(['partner_name' => 'Partner A Wholesaler', 'license_status' => 'valid']);
        } finally {
            tenancy()->initialize($tenant);
            TenantSettings::forTenant($tenant)->setAffiliationCode($this->priorAffiliationCode);
            $tenant->save();
            $this->cleanupTenantRows();
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            tenancy()->end();
        }
    }

    #[Test]
    public function pharmacy_profile_is_forbidden_even_with_buying_group_ability(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Pharmacy);

        try {
            $user = User::factory()->create();
            $this->userIds[] = (int) $user->getKey();
            $token = $user->createToken('bg-api', [SanctumAbilities::BUYING_GROUP_NETWORK])->plainTextToken;
            tenancy()->end();

            $this->tenantApiGet('/api/v1/buying-group/members', $token)->assertForbidden();
            $this->tenantApiGet('/api/v1/buying-group/network/summary', $token)->assertForbidden();
        } finally {
            tenancy()->initialize($tenant);
            $this->cleanupTenantRows();
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            tenancy()->end();
        }
    }

    #[Test]
    public function missing_ability_is_forbidden_on_buying_group_tenant(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::BuyingGroup);

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::BuyingGroup);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->userIds[] = (int) $user->getKey();
            $token = $user->createToken('wrong-ability', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;
            tenancy()->end();

            $this->tenantApiGet('/api/v1/buying-group/members', $token)->assertForbidden();
        } finally {
            tenancy()->initialize($tenant);
            $this->cleanupTenantRows();
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            tenancy()->end();
        }
    }

    private function createBuyingGroupToken(): string
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::BuyingGroup);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user->createToken('bg-api', [SanctumAbilities::BUYING_GROUP_NETWORK])->plainTextToken;
    }

    private function tenantApiGet(string $uri, ?string $token): TestResponse
    {
        $path = str_starts_with($uri, '/') ? $uri : '/'.$uri;
        $absolute = 'http://'.self::DEMO2_DOMAIN.$path;

        $server = [
            'HTTP_HOST' => self::DEMO2_DOMAIN,
            'HTTP_ACCEPT' => 'application/json',
        ];

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call('GET', $absolute, [], [], [], $server);
    }

    private function initializeDemo2Tenant(TenantProfile $profile): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => $profile === TenantProfile::BuyingGroup ? 'Demo Buying Group' : 'Demo Pharmacy',
                'profile' => $profile,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->forceFill(['profile' => $profile])->save();
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        $this->artisan('tenants:migrate', [
            '--tenants' => [self::DEMO2_TENANT_ID],
            '--force' => true,
        ])->assertSuccessful();

        tenancy()->initialize($tenant);

        return $tenant;
    }

    private function cleanupTenantRows(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->metricMemberTenantIds !== []) {
            BuyingGroupMemberMetric::query()
                ->whereIn('member_tenant_id', $this->metricMemberTenantIds)
                ->delete();
            BuyingGroupPartnerFact::query()
                ->whereIn('member_tenant_id', $this->metricMemberTenantIds)
                ->delete();
            $this->metricMemberTenantIds = [];
        }

        if ($this->memberIds !== []) {
            BuyingGroupMember::query()->whereIn('id', $this->memberIds)->delete();
            $this->memberIds = [];
        }

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }
    }
}
