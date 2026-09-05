<?php

namespace Tests\Feature\Integrations;

use App\Enums\TenantProfile;
use App\Models\InboundConnection;
use App\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RedactInboundConnectionActivitySecretsCommandTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $activityId = null;

    #[Test]
    public function command_scrubs_credentials_and_inbound_token_from_activity_properties(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $activity = Activity::query()->create([
                'log_name' => 'default',
                'description' => 'updated',
                'subject_type' => (new InboundConnection)->getMorphClass(),
                'subject_id' => 999001,
                'event' => 'updated',
                'properties' => [
                    'attributes' => [
                        'name' => 'Scrub Me',
                        'credentials' => ['password' => 'leaked-secret'],
                        'inbound_token' => 'tok-leaked',
                    ],
                    'old' => [
                        'name' => 'Before',
                        'credentials' => ['password' => 'old-leaked'],
                        'inbound_token' => 'tok-old',
                    ],
                ],
            ]);
            $this->activityId = (int) $activity->getKey();

            $this->artisan('activitylog:redact-inbound-connection-secrets', [
                '--tenant' => self::DEMO2_TENANT_ID,
            ])
                ->expectsOutputToContain('Redacted 1')
                ->assertSuccessful();

            // The command ends tenancy in its finally block; restore for assertions.
            $this->initializeDemo2Tenant();

            $fresh = Activity::query()->findOrFail($this->activityId);
            $properties = $fresh->properties?->toArray() ?? [];

            $this->assertSame('Scrub Me', $properties['attributes']['name'] ?? null);
            $this->assertSame('Before', $properties['old']['name'] ?? null);
            $this->assertArrayNotHasKey('credentials', $properties['attributes'] ?? []);
            $this->assertArrayNotHasKey('inbound_token', $properties['attributes'] ?? []);
            $this->assertArrayNotHasKey('credentials', $properties['old'] ?? []);
            $this->assertArrayNotHasKey('inbound_token', $properties['old'] ?? []);
        } finally {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->activityId !== null) {
                Activity::query()->whereKey($this->activityId)->delete();
            }

            tenancy()->end();
        }

        $this->activityId = null;
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
