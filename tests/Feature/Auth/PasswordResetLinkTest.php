<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\TenantProfile;
use App\Filament\Admin\Pages\Auth\Login as AdminLogin;
use App\Filament\App\Pages\Auth\Login as AppLogin;
use App\Models\Tenant;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordResetLinkTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    #[Test]
    public function app_and_admin_panels_enable_password_reset(): void
    {
        $this->assertTrue(Filament::getPanel('app')->hasPasswordReset());
        $this->assertSame('users', Filament::getPanel('app')->getAuthPasswordBroker());

        $this->assertTrue(Filament::getPanel('admin')->hasPasswordReset());
        $this->assertSame('admins', Filament::getPanel('admin')->getAuthPasswordBroker());
    }

    #[Test]
    public function admin_login_shows_forgot_password_link(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(AdminLogin::class)
            ->assertSuccessful()
            ->assertSee(__('filament-panels::auth/pages/login.actions.request_password_reset.label'), false);
    }

    #[Test]
    public function app_login_shows_forgot_password_link_when_password_login_enabled(): void
    {
        $tenant = $this->ensureDemo2Tenant();
        TenantSettings::forTenant($tenant)->saveSsoConfig([
            'enabled' => false,
            'sso_only' => false,
            'provider' => 'entra',
            'issuer' => '',
            'client_id' => '',
            'client_secret' => '',
            'entra_tenant_id' => null,
            'jit_default_role' => null,
            'allowed_email_domains' => [],
        ]);
        $tenant->save();

        tenancy()->initialize($tenant);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(AppLogin::class)
                ->assertSuccessful()
                ->assertSee(__('filament-panels::auth/pages/login.actions.request_password_reset.label'), false);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
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

        return $tenant;
    }
}
