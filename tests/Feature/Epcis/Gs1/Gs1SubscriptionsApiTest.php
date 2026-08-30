<?php

declare(strict_types=1);

namespace Tests\Feature\Epcis\Gs1;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\EpcisSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\SanctumAbilities;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Gs1SubscriptionsApiTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $subscriptionIds = [];

    #[Test]
    public function subscribe_list_and_unsubscribe(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::factory()->create();
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user->assignRole(TenantRole::Owner->value);
            $token = $user->createToken('subs', [SanctumAbilities::EPCIS_SUBSCRIPTIONS])->plainTextToken;
            tenancy()->end();

            $create = $this->tenantApiJson('POST', '/api/v1/epcis/subscriptions', $token, [
                'destination' => 'https://hooks.partner.example/epcis',
                'queryName' => 'SimpleEventQuery',
                'schedule' => 'realtime',
                'params' => ['EQ_bizStep' => 'urn:epcglobal:cbv:bizstep:shipping'],
            ]);

            $create->assertCreated()
                ->assertJsonPath('type', 'SubscribeSuccess')
                ->assertJsonStructure(['subscriptionID', 'secret']);

            $subscriptionId = (string) $create->json('subscriptionID');

            tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));
            $row = EpcisSubscription::query()->where('subscription_uuid', $subscriptionId)->first();
            $this->assertNotNull($row);
            $this->subscriptionIds[] = (int) $row->getKey();
            tenancy()->end();

            $list = $this->tenantApiJson('GET', '/api/v1/epcis/subscriptions', $token);
            $list->assertOk();
            $ids = collect($list->json('subscriptions'))->pluck('subscriptionID')->all();
            $this->assertContains($subscriptionId, $ids);

            $delete = $this->tenantApiJson('DELETE', '/api/v1/epcis/subscriptions/'.$subscriptionId, $token);
            $delete->assertOk()->assertJsonPath('type', 'UnsubscribeSuccess');

            tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));
            $this->assertNull(EpcisSubscription::query()->where('subscription_uuid', $subscriptionId)->first());
            $this->subscriptionIds = [];
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function subscribe_rejects_loopback_destination(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::factory()->create();
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user->assignRole(TenantRole::Owner->value);
            $token = $user->createToken('subs', [SanctumAbilities::EPCIS_SUBSCRIPTIONS])->plainTextToken;
            tenancy()->end();

            $this->tenantApiJson('POST', '/api/v1/epcis/subscriptions', $token, [
                'destination' => 'http://127.0.0.1/hook',
            ])->assertStatus(422)
                ->assertJsonPath('type', 'SubscribeNotPermittedException');
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
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);
        $this->assertTrue(Schema::hasColumn('epcis_subscriptions', 'subscription_uuid'));

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
            if ($tenant !== null) {
                tenancy()->initialize($tenant);
            }
        }

        if (tenancy()->initialized) {
            foreach ($this->subscriptionIds as $id) {
                EpcisSubscription::query()->whereKey($id)->delete();
            }
            $this->subscriptionIds = [];
            tenancy()->end();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function tenantApiJson(string $method, string $uri, string $token, array $payload = []): TestResponse
    {
        $path = str_starts_with($uri, '/') ? $uri : '/'.$uri;
        $absolute = 'http://'.self::DEMO2_DOMAIN.$path;
        $server = [
            'HTTP_HOST' => self::DEMO2_DOMAIN,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ];

        return $this->call($method, $absolute, [], [], [], $server, $payload === [] ? null : json_encode($payload));
    }
}
