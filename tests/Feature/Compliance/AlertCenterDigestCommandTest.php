<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ComplianceAlertNotification;
use App\Support\Auth\SupportEngineerEmail;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Compliance\ComplianceAlertMetrics;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Tests\TestCase;

class AlertCenterDigestCommandTest extends TestCase
{
    private const TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private static bool $tenantReady = false;

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $connectionIds = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            if ($this->connectionIds !== []) {
                InboundConnection::query()->whereIn('id', $this->connectionIds)->delete();
            }
            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
            }
            tenancy()->end();
        }

        parent::tearDown();
    }

    #[Test]
    public function digest_command_notifies_compliance_contact_when_alerts_exist(): void
    {
        $tenant = $this->initializeTenant();

        try {
            Notification::fake();

            TenantSettings::forTenant($tenant)
                ->setAlertDigestEnabled(true)
                ->setAlertDigestFrequency('daily')
                ->setComplianceContactEmail('compliance-digest-'.substr((string) str()->uuid(), 0, 8).'@demo.test')
                ->setReceivingState(null);
            $tenant->save();

            $this->artisan('compliance:alert-center-digest', [
                '--tenant' => self::TENANT_ID,
                '--force' => true,
            ])->assertSuccessful();

            Notification::assertSentOnDemand(ComplianceAlertNotification::class);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function digest_command_skips_when_disabled(): void
    {
        $tenant = $this->initializeTenant();

        try {
            Notification::fake();

            TenantSettings::forTenant($tenant)->setAlertDigestEnabled(false);
            $tenant->save();

            $this->artisan('compliance:alert-center-digest', [
                '--tenant' => self::TENANT_ID,
                '--force' => true,
            ])->assertSuccessful();

            Notification::assertNothingSent();
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function silence_is_not_an_integration_alert(): void
    {
        $this->initializeTenant();

        $connection = $this->createInboundConnection([
            'name' => 'Quiet Hub '.substr((string) str()->uuid(), 0, 8),
            'last_received_at' => now()->subDays(30),
            'last_error' => null,
        ]);

        $titles = collect(app(ComplianceAlertMetrics::class)->alerts(null))->pluck('title');

        $this->assertFalse($titles->contains('Stale inbound connections'));
        $this->assertFalse($titles->contains('Stale outbound connections'));
        $this->assertNotNull($connection->getKey());
    }

    #[Test]
    public function inbound_connection_error_is_integration_audience_and_emails_support_engineer(): void
    {
        $tenant = $this->initializeTenant();
        Notification::fake();
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        TenantSettings::forTenant($tenant)
            ->setAlertDigestEnabled(true)
            ->setAlertDigestFrequency('daily')
            ->setComplianceContactEmail('compliance-only-'.substr((string) str()->uuid(), 0, 8).'@demo.test')
            ->setReceivingState('CA');
        $tenant->save();

        $this->createInboundConnection([
            'name' => 'Broken Hub '.substr((string) str()->uuid(), 0, 8),
            'last_received_at' => now()->subDay(),
            'last_error' => 'Webhook auth failed',
        ]);

        $support = User::factory()->create([
            'email' => 'ops-se-'.substr((string) str()->uuid(), 0, 8).'@tracepharma.io',
        ]);
        $support->assignRole(TenantRole::SupportEngineer->value);
        $this->userIds[] = (int) $support->getKey();

        $integrationAlerts = app(ComplianceAlertMetrics::class)
            ->alertsForAudience(ComplianceAlertMetrics::AUDIENCE_INTEGRATION);

        $this->assertTrue(
            collect($integrationAlerts)->contains(
                fn (array $alert): bool => $alert['title'] === 'Inbound connection errors',
            ),
        );

        $this->artisan('compliance:alert-center-digest', [
            '--tenant' => self::TENANT_ID,
            '--force' => true,
        ])->assertSuccessful();

        Notification::assertSentOnDemand(
            ComplianceAlertNotification::class,
            function (ComplianceAlertNotification $notification, array $channels, object $notifiable) use ($support): bool {
                return data_get($notifiable->routes ?? [], 'mail') === $support->email
                    && str_contains($notification->subject, 'integration alert');
            },
        );
    }

    #[Test]
    public function integration_digest_falls_back_to_ops_inbox_without_support_engineer(): void
    {
        $tenant = $this->initializeTenant();
        Notification::fake();
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        TenantSettings::forTenant($tenant)
            ->setAlertDigestEnabled(true)
            ->setAlertDigestFrequency('daily')
            ->setComplianceContactEmail('compliance-only-'.substr((string) str()->uuid(), 0, 8).'@demo.test')
            ->setReceivingState('CA');
        $tenant->save();

        try {
            User::role(TenantRole::SupportEngineer->value)->get()->each(function (User $user): void {
                $user->removeRole(TenantRole::SupportEngineer->value);
            });
        } catch (RoleDoesNotExist) {
            // ok
        }

        $this->createInboundConnection([
            'name' => 'Broken Hub No SE '.substr((string) str()->uuid(), 0, 8),
            'last_error' => 'SFTP auth failed',
        ]);

        $this->artisan('compliance:alert-center-digest', [
            '--tenant' => self::TENANT_ID,
            '--force' => true,
        ])->assertSuccessful();

        Notification::assertSentOnDemand(
            ComplianceAlertNotification::class,
            function (ComplianceAlertNotification $notification, array $channels, object $notifiable): bool {
                return data_get($notifiable->routes ?? [], 'mail') === SupportEngineerEmail::OPS_INBOX
                    && str_contains($notification->subject, 'integration alert');
            },
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createInboundConnection(array $attributes): InboundConnection
    {
        $connection = InboundConnection::query()->create(array_merge([
            'serialization_provider' => SerializationProvider::Other,
            'transport' => InboundTransport::Https,
            'is_active' => true,
        ], $attributes));
        $this->connectionIds[] = (int) $connection->getKey();

        return $connection;
    }

    private function initializeTenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => 'tenant_demo2_internal_vatengi_com',
            ]));
            $tenant->domains()->create(['domain' => 'demo2.internal.vatengi.com']);
        }

        if (! self::$tenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$tenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }
}
