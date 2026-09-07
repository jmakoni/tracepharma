<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Tenants\ActivateTenant;
use App\Actions\Tenants\SuspendTenant;
use App\Enums\TenantProfile;
use App\Models\PlatformAuditEvent;
use App\Models\Tenant;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class TenantAuthorizationLifecycleTest extends TestCase
{
    /** @var list<string> */
    private array $tenantIds = [];

    #[Test]
    public function suspend_requires_a_reason_and_stamps_audit_fields(): void
    {
        $tenant = $this->makeTenant();

        try {
            app(SuspendTenant::class)->handle($tenant, '   ');
            $this->fail('Expected RuntimeException for empty reason.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('reason', $exception->getMessage());
        }

        $suspended = app(SuspendTenant::class)->handle($tenant, 'DSCSA data mismatch investigation');

        $this->assertSame('suspended', $suspended->status);
        $this->assertSame('DSCSA data mismatch investigation', $suspended->suspension_reason);
        $this->assertNotNull($suspended->suspended_at);

        $this->assertDatabaseHas('platform_audit_events', [
            'action' => 'tenant.suspended',
            'tenant_id' => (string) $tenant->getKey(),
        ]);
    }

    #[Test]
    public function activate_clears_suspension_and_audits(): void
    {
        $tenant = $this->makeTenant();
        app(SuspendTenant::class)->handle($tenant, 'Hold for review');

        $activated = app(ActivateTenant::class)->handle($tenant->fresh());

        $this->assertSame('active', $activated->status);
        $this->assertNull($activated->suspension_reason);
        $this->assertNull($activated->suspended_at);

        $this->assertDatabaseHas('platform_audit_events', [
            'action' => 'tenant.activated',
            'tenant_id' => (string) $tenant->getKey(),
        ]);
    }

    #[Test]
    public function suspend_and_activate_commands_wrap_the_actions(): void
    {
        $tenant = $this->makeTenant();

        $this->artisan('tenant:suspend', ['tenant' => $tenant->getKey()])
            ->assertFailed();

        $this->artisan('tenant:suspend', [
            'tenant' => $tenant->getKey(),
            '--reason' => 'Chargeback dispute',
        ])->assertSuccessful();

        $this->assertSame('suspended', $tenant->fresh()->status);

        $this->artisan('tenant:activate', ['tenant' => $tenant->getKey()])
            ->assertSuccessful();

        $this->assertSame('active', $tenant->fresh()->status);
    }

    #[Test]
    public function double_suspend_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        app(SuspendTenant::class)->handle($tenant, 'First suspension');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already suspended');

        app(SuspendTenant::class)->handle($tenant->fresh(), 'Second suspension');
    }

    #[Test]
    public function audit_event_records_admin_actor_when_present(): void
    {
        $tenant = $this->makeTenant();

        app(SuspendTenant::class)->handle($tenant, 'Actor check');

        $event = PlatformAuditEvent::query()
            ->where('action', 'tenant.suspended')
            ->where('tenant_id', (string) $tenant->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(['reason' => 'Actor check', 'previous_status' => 'active'], $event->payload);
    }

    private function makeTenant(): Tenant
    {
        $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Audit Tenant '.Str::random(6),
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
            'tenancy_db_name' => 'tenant_audit_'.Str::random(8),
        ]));

        $this->tenantIds[] = (string) $tenant->getKey();

        return $tenant;
    }

    protected function tearDown(): void
    {
        if ($this->tenantIds !== []) {
            PlatformAuditEvent::query()->whereIn('tenant_id', $this->tenantIds)->delete();
            Tenant::query()->whereIn('id', $this->tenantIds)->delete();
            $this->tenantIds = [];
        }

        parent::tearDown();
    }
}
