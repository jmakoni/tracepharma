<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\TenantProfile;
use App\Filament\Notifications\Notification;
use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FilamentDatabaseNotificationsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function app_notification_persists_to_database_for_authenticated_user(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $user = User::factory()->create();
            $this->actingAs($user, 'web');
            Filament::setCurrentPanel(Filament::getPanel('app'));

            DB::table('notifications')->delete();

            Notification::make()
                ->title('Persisted toast')
                ->success()
                ->send();

            $this->assertDatabaseCount('notifications', 1);
            $this->assertDatabaseHas('notifications', [
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->getKey(),
            ]);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function ephemeral_notification_does_not_persist(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::factory()->create();
            $this->actingAs($user, 'web');
            Filament::setCurrentPanel(Filament::getPanel('app'));

            DB::table('notifications')->delete();

            Notification::make()
                ->title('Scan beep')
                ->success()
                ->ephemeral()
                ->send();

            $this->assertDatabaseCount('notifications', 0);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function admin_notification_persists_to_central_database(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        DB::connection()->table('notifications')->delete();

        Notification::make()
            ->title('Admin persisted')
            ->success()
            ->send();

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => $admin->getMorphClass(),
            'notifiable_id' => $admin->getKey(),
        ]);
    }

    #[Test]
    public function app_panel_login_still_loads_with_database_notifications_enabled(): void
    {
        $this->ensureDemo2Tenant();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->get('https://'.self::DEMO2_DOMAIN.'/login', [
            'HTTP_HOST' => self::DEMO2_DOMAIN,
        ])->assertOk();
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = $this->ensureDemo2Tenant();
        tenancy()->initialize($tenant);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        return $tenant;
    }

    private function ensureDemo2Tenant(): Tenant
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

        return $tenant->fresh();
    }
}
