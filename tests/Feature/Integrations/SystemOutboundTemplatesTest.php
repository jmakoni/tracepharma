<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Outbound\EnsureSystemOutboundTemplates;
use App\Enums\OutboundTransport;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\OutboundConnectionPolicy;
use App\Support\Auth\JobRoleAccess;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SystemOutboundTemplatesTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $createdTemplateIds = [];

    #[Test]
    public function ensure_system_outbound_templates_creates_two_inactive_system_rows_idempotently(): void
    {
        $this->initializeDemo2Tenant();

        try {
            OutboundConnection::query()
                ->whereIn('system_key', [
                    OutboundConnection::SYSTEM_KEY_EMAIL_ATTACHMENT,
                    OutboundConnection::SYSTEM_KEY_CLIENT_PORTAL,
                ])
                ->delete();

            $first = app(EnsureSystemOutboundTemplates::class)->handle();
            $this->assertSame(2, $first['created']);
            $this->assertSame(0, $first['existing']);

            $email = OutboundConnection::query()
                ->where('system_key', OutboundConnection::SYSTEM_KEY_EMAIL_ATTACHMENT)
                ->first();
            $portal = OutboundConnection::query()
                ->where('system_key', OutboundConnection::SYSTEM_KEY_CLIENT_PORTAL)
                ->first();

            $this->assertNotNull($email);
            $this->assertNotNull($portal);
            $this->createdTemplateIds = [(int) $email->getKey(), (int) $portal->getKey()];

            $this->assertTrue($email->is_system);
            $this->assertTrue($portal->is_system);
            $this->assertFalse($email->is_active);
            $this->assertFalse($portal->is_active);
            $this->assertSame(OutboundTransport::Email, $email->transport);
            $this->assertSame(OutboundTransport::Portal, $portal->transport);

            $second = app(EnsureSystemOutboundTemplates::class)->handle();
            $this->assertSame(0, $second['created']);
            $this->assertSame(2, $second['existing']);

            $this->assertSame(
                2,
                OutboundConnection::query()
                    ->whereIn('system_key', [
                        OutboundConnection::SYSTEM_KEY_EMAIL_ATTACHMENT,
                        OutboundConnection::SYSTEM_KEY_CLIENT_PORTAL,
                    ])
                    ->count(),
            );
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function outbound_connection_policy_cannot_delete_system_template(): void
    {
        $this->assertFalse(JobRoleAccess::enabled());

        $policy = new OutboundConnectionPolicy;
        $system = new OutboundConnection;
        $system->forceFill([
            'is_system' => true,
            'system_key' => OutboundConnection::SYSTEM_KEY_EMAIL_ATTACHMENT,
        ]);
        $owner = $this->userWithRoles(TenantRole::Owner);

        $this->assertFalse($policy->delete($owner, $system));
    }

    private function userWithRoles(TenantRole ...$roles): User
    {
        $user = new User;

        $user->setRelation('roles', collect(array_map(
            static fn (TenantRole $role): Role => new Role(['name' => $role->value, 'guard_name' => 'web']),
            $roles,
        )));

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
            return;
        }

        // Re-seed system templates so demo2 stays consistent for other tests / ops.
        app(EnsureSystemOutboundTemplates::class)->handle();
        $this->createdTemplateIds = [];
    }
}
