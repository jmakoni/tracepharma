<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\InboundTransport;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ConnectionFailureStreakAlert;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Integrations\ConnectionHealthTracker;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectionHealthSweepTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $outboundIds = [];

    /** @var list<int> */
    private array $inboundIds = [];

    #[Test]
    public function tracker_counts_failures_and_resets_on_success(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $connection = $this->makeOutboundConnection();
            $tracker = app(ConnectionHealthTracker::class);

            $tracker->recordFailure($connection, 'HTTP 500');
            $tracker->recordFailure($connection->fresh(), 'HTTP 502');

            $fresh = $connection->fresh();
            $this->assertSame(2, (int) $fresh->consecutive_failures);
            $this->assertNotNull($fresh->last_failure_at);
            $this->assertSame('HTTP 502', $fresh->last_error);

            $tracker->recordSuccess($fresh);

            $fresh = $connection->fresh();
            $this->assertSame(0, (int) $fresh->consecutive_failures);
            $this->assertNotNull($fresh->last_success_at);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function sweep_alerts_owner_at_threshold_without_pausing_by_default(): void
    {
        Notification::fake();
        $this->initializeDemo2Tenant();

        try {
            $this->createOwner();
            $this->resetAllStreaks();

            $connection = $this->makeOutboundConnection();
            $connection->forceFill(['consecutive_failures' => 3, 'last_error' => 'HTTP 401'])->save();

            $this->artisan('connections:health-sweep', ['--tenant' => self::DEMO2_TENANT_ID])
                ->assertSuccessful();

            // The command's TenantRunner ends tenancy; re-enter for assertions.
            $this->reinitializeTenant();

            Notification::assertSentTo(
                User::role(TenantRole::Owner->value)->get(),
                ConnectionFailureStreakAlert::class,
                fn (ConnectionFailureStreakAlert $n): bool => $n->connectionName === $connection->name
                    && $n->consecutiveFailures === 3
                    && $n->autoPaused === false,
            );

            $this->assertTrue((bool) $connection->fresh()->is_active);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function sweep_auto_pauses_at_ten_failures_when_tenant_opted_in(): void
    {
        Notification::fake();
        $this->initializeDemo2Tenant();

        try {
            $this->createOwner();
            $this->resetAllStreaks();
            TenantSettings::forTenant(tenant())->setAutoPauseOnFailureStreak(true);

            $connection = $this->makeOutboundConnection();
            $connection->forceFill(['consecutive_failures' => 10, 'last_error' => 'timeout'])->save();

            $this->artisan('connections:health-sweep', ['--tenant' => self::DEMO2_TENANT_ID])
                ->assertSuccessful();

            $this->reinitializeTenant();

            $this->assertFalse((bool) $connection->fresh()->is_active);

            Notification::assertSentTo(
                User::role(TenantRole::Owner->value)->get(),
                ConnectionFailureStreakAlert::class,
                fn (ConnectionFailureStreakAlert $n): bool => $n->autoPaused === true,
            );

            $this->assertDatabaseHas('activity_log', [
                'subject_type' => (new OutboundConnection)->getMorphClass(),
                'subject_id' => $connection->getKey(),
                'description' => 'connection_auto_paused_failure_streak',
            ]);

            TenantSettings::forTenant(tenant())->setAutoPauseOnFailureStreak(false);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function sweep_ignores_connections_below_threshold(): void
    {
        Notification::fake();
        $this->initializeDemo2Tenant();

        try {
            $this->createOwner();
            $this->resetAllStreaks();

            $connection = $this->makeOutboundConnection();
            $connection->forceFill(['consecutive_failures' => 2])->save();

            $inbound = InboundConnection::query()->create([
                'name' => 'Health Sweep Inbound '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
                'consecutive_failures' => 1,
            ]);
            $this->inboundIds[] = (int) $inbound->getKey();

            $this->artisan('connections:health-sweep', ['--tenant' => self::DEMO2_TENANT_ID])
                ->assertSuccessful();

            $this->reinitializeTenant();

            Notification::assertNothingSent();
        } finally {
            $this->cleanup();
        }
    }

    private function resetAllStreaks(): void
    {
        OutboundConnection::query()->where('consecutive_failures', '>', 0)->update(['consecutive_failures' => 0]);
        InboundConnection::query()->where('consecutive_failures', '>', 0)->update(['consecutive_failures' => 0]);
    }

    private function makeOutboundConnection(): OutboundConnection
    {
        $connection = OutboundConnection::query()->create([
            'name' => 'Health Sweep '.Str::random(6),
            'serialization_provider' => SerializationProvider::CustomHttps,
            'transport' => OutboundTransport::Https,
            'is_active' => true,
            'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
        ]);
        $this->outboundIds[] = (int) $connection->getKey();

        return $connection;
    }

    private function createOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
        $user = User::factory()->create([
            'email' => 'owner-health-'.Str::uuid().'@example.com',
        ]);
        $user->assignRole(TenantRole::Owner->value);

        return $user;
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Wholesaler',
                'profile' => TenantProfile::DrugWholesaler,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
            if ($tenant->profile !== TenantProfile::DrugWholesaler) {
                $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();
            }
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

    private function reinitializeTenant(): void
    {
        if (! tenancy()->initialized) {
            tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));
        }
    }

    private function cleanup(): void
    {
        $this->reinitializeTenant();

        TenantSettings::forTenant(tenant())->setAutoPauseOnFailureStreak(false);

        if ($this->outboundIds !== []) {
            OutboundConnection::query()->whereIn('id', $this->outboundIds)->delete();
            $this->outboundIds = [];
        }

        if ($this->inboundIds !== []) {
            InboundConnection::query()->whereIn('id', $this->inboundIds)->delete();
            $this->inboundIds = [];
        }

        tenancy()->end();
    }
}
