<?php

namespace Tests\Feature\Tracing;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Enums\TracingRequestorType;
use App\Enums\TracingRequestStatus;
use App\Models\Tenant;
use App\Models\TracingRequest;
use App\Models\User;
use App\Notifications\ComplianceAlertNotification;
use App\Support\Auth\TenantRoleSeeder;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckTracingSlaCommandTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $requestIds = [];

    #[Test]
    public function overdue_open_request_is_flagged_and_owners_notified_once(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $owner = User::factory()->create();
            $owner->syncRoles([TenantRole::Owner->value]);

            $request = TracingRequest::query()->create([
                'title' => 'Overdue regulator trace',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Regulator,
                'requested_at' => now()->subDays(2),
                'due_at' => now()->subHour(),
                'sla_breached' => false,
            ]);
            $this->requestIds[] = (int) $request->getKey();

            Notification::fake();

            $this->artisan('tracing:check-sla', ['--tenant' => self::DEMO2_TENANT_ID])
                ->assertSuccessful();

            tenancy()->initialize($tenant);
            $this->assertTrue($request->fresh()?->sla_breached);

            Notification::assertSentTo(
                $owner,
                ComplianceAlertNotification::class,
                function (ComplianceAlertNotification $notification): bool {
                    return $notification->tenantId === self::DEMO2_TENANT_ID
                        && $notification->actionPath === '/tracing-requests';
                },
            );

            Notification::fake();

            $this->artisan('tracing:check-sla', ['--tenant' => self::DEMO2_TENANT_ID])
                ->assertSuccessful();

            tenancy()->initialize($tenant);
            Notification::assertNothingSent();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function dry_run_does_not_flag_or_notify(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $request = TracingRequest::query()->create([
                'title' => 'Dry-run overdue trace',
                'status' => TracingRequestStatus::Open,
                'requestor_type' => TracingRequestorType::Internal,
                'requested_at' => now()->subDays(2),
                'due_at' => now()->subHour(),
                'sla_breached' => false,
            ]);
            $this->requestIds[] = (int) $request->getKey();

            Notification::fake();

            $this->artisan('tracing:check-sla', [
                '--tenant' => self::DEMO2_TENANT_ID,
                '--dry-run' => true,
            ])->assertSuccessful();

            tenancy()->initialize($tenant);
            $this->assertFalse((bool) $request->fresh()?->sla_breached);
            Notification::assertNothingSent();
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
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
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
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant !== null && ! tenancy()->initialized) {
            tenancy()->initialize($tenant);
        }

        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->requestIds as $id) {
            TracingRequest::query()->whereKey($id)->delete();
        }

        $this->requestIds = [];
        tenancy()->end();
    }
}
