<?php

namespace Tests\Feature\Integrations;

use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\InboundConnection;
use App\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * InboundConnection stores partner credentials and inbound_token used to authenticate
 * webhooks. Those secrets must never land in Spatie activity_log properties.
 */
class InboundConnectionActivityLogTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $connectionId = null;

    #[Test]
    public function inbound_connection_activity_excludes_credentials_and_inbound_token(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $connection = InboundConnection::query()->create([
                'name' => 'Secret Inbound',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
                'credentials' => [
                    'username' => 'partner-user',
                    'password' => 'partner-secret',
                ],
                'inbound_token' => 'tok-initial-secret-value',
            ]);
            $this->connectionId = (int) $connection->getKey();

            $connection->update([
                'name' => 'Renamed Inbound',
                'credentials' => [
                    'username' => 'partner-user',
                    'password' => 'rotated-secret',
                ],
            ]);

            $activity = $this->latestActivityFor($connection);
            $this->assertNotNull($activity, 'Updating an inbound connection must write an activity record.');

            $changes = $activity->attribute_changes?->toArray() ?? [];
            $attributes = $changes['attributes'] ?? [];
            $old = $changes['old'] ?? [];

            $this->assertSame('Renamed Inbound', $attributes['name'] ?? null);
            $this->assertSame('Secret Inbound', $old['name'] ?? null);

            $this->assertArrayNotHasKey('credentials', $attributes);
            $this->assertArrayNotHasKey('credentials', $old);
            $this->assertArrayNotHasKey('inbound_token', $attributes);
            $this->assertArrayNotHasKey('inbound_token', $old);

            $encoded = json_encode($activity->properties?->toArray() ?? []);
            $this->assertIsString($encoded);
            $this->assertStringNotContainsString('partner-secret', $encoded);
            $this->assertStringNotContainsString('rotated-secret', $encoded);
            $this->assertStringNotContainsString('tok-initial-secret-value', $encoded);
        } finally {
            $this->cleanup();
        }
    }

    private function latestActivityFor(InboundConnection $connection): ?Activity
    {
        return Activity::query()
            ->where('subject_type', $connection->getMorphClass())
            ->where('subject_id', $connection->getKey())
            ->orderByDesc('id')
            ->first();
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->connectionId !== null) {
                Activity::query()
                    ->where('subject_type', (new InboundConnection)->getMorphClass())
                    ->where('subject_id', $this->connectionId)
                    ->delete();

                InboundConnection::query()->whereKey($this->connectionId)->delete();
            }

            tenancy()->end();
        }

        $this->connectionId = null;
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
}
