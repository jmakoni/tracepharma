<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\TenantProfile;
use App\Filament\Admin\Pages\Auth\Login as AdminLogin;
use App\Filament\App\Pages\Auth\Login as AppLogin;
use App\Http\Middleware\EnsurePasswordChangeRequired;
use App\Models\Admin;
use App\Models\PortalUser;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PortalOtpNotification;
use App\Services\Auth\Oidc\OidcAuthenticator;
use App\Services\Portal\PortalOtpService;
use App\Enums\TenantRole;
use App\Support\Auth\AccountSecuritySession;
use App\Support\Auth\OidcProvider;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\SanctumAbilities;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccountSecurityAuthTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $adminIds = [];

    /** @var list<int> */
    private array $portalUserIds = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            foreach ($this->userIds as $id) {
                User::query()->whereKey($id)->delete();
            }
            foreach ($this->portalUserIds as $id) {
                PortalUser::query()->whereKey($id)->delete();
            }
            tenancy()->end();
        }

        foreach ($this->adminIds as $id) {
            Admin::query()->whereKey($id)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function disabled_user_cannot_password_login(): void
    {
        $this->initializeDemo2Tenant();

        $user = User::factory()->disabled()->create([
            'email' => 'disabled-'.Str::lower(Str::random(6)).'@example.com',
            'password' => 'password',
        ]);
        $this->userIds[] = (int) $user->getKey();

        $this->get('https://'.self::DEMO2_DOMAIN.'/login');
        Filament::setCurrentPanel(Filament::getPanel('app'));

        Livewire::test(AppLogin::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasErrors(['data.email']);

        $this->assertGuest('web');
    }

    #[Test]
    public function failed_password_logins_lock_user_account(): void
    {
        config([
            'tracepharma.account_security.max_failed_logins' => 3,
            'tracepharma.account_security.lockout_minutes' => 15,
        ]);

        $this->initializeDemo2Tenant();

        $user = User::factory()->create([
            'email' => 'lock-'.Str::lower(Str::random(6)).'@example.com',
            'password' => 'password',
        ]);
        $this->userIds[] = (int) $user->getKey();

        $this->get('https://'.self::DEMO2_DOMAIN.'/login');
        Filament::setCurrentPanel(Filament::getPanel('app'));

        for ($i = 0; $i < 3; $i++) {
            Livewire::test(AppLogin::class)
                ->fillForm([
                    'email' => $user->email,
                    'password' => 'wrong-password',
                ])
                ->call('authenticate')
                ->assertHasErrors(['data.email']);
        }

        $user = $user->fresh();
        $this->assertTrue($user->isLocked());
        $this->assertSame(3, (int) $user->failed_login_count);
    }

    #[Test]
    public function disabled_admin_cannot_password_login(): void
    {
        $admin = Admin::factory()->disabled()->create([
            'email' => 'disabled-admin-'.Str::lower(Str::random(6)).'@example.com',
            'password' => 'password',
        ]);
        $this->adminIds[] = (int) $admin->getKey();

        $adminDomain = (string) config('tracepharma.admin_domain');
        $this->get('https://'.$adminDomain.'/login');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(AdminLogin::class)
            ->fillForm([
                'email' => $admin->email,
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasErrors(['data.email']);

        $this->assertGuest('admin');
    }

    #[Test]
    public function oidc_login_refuses_disabled_user(): void
    {
        $this->initializeDemo2Tenant();

        $user = User::factory()->disabled()->create([
            'email' => 'oidc-disabled-'.Str::lower(Str::random(6)).'@example.com',
        ]);
        $this->userIds[] = (int) $user->getKey();

        $method = new ReflectionMethod(OidcAuthenticator::class, 'loginAndRedirect');
        $method->setAccessible(true);

        try {
            $method->invoke(app(OidcAuthenticator::class), $user, 'web', 'app');
            $this->fail('Expected abort for disabled OIDC user.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertStringContainsString('disabled', strtolower($e->getMessage()));
        }

        $this->assertGuest('web');
    }

    #[Test]
    public function must_change_password_middleware_redirects_to_profile(): void
    {
        $this->initializeDemo2Tenant();

        $user = User::factory()->mustChangePassword()->create([
            'email' => 'must-change-'.Str::lower(Str::random(6)).'@example.com',
        ]);
        $this->userIds[] = (int) $user->getKey();

        Auth::guard('web')->login($user);
        AccountSecuritySession::bind($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $request = Request::create('https://'.self::DEMO2_DOMAIN.'/', 'GET');
        $request->setLaravelSession($this->app['session']->driver());

        $response = app(EnsurePasswordChangeRequired::class)->handle($request, fn () => response('ok'));

        $this->assertTrue($response->isRedirect());
        $this->assertStringContainsString('my-profile', $response->headers->get('Location') ?? '');
    }

    #[Test]
    public function portal_otp_rejects_disabled_and_locks_after_failures(): void
    {
        config([
            'tracepharma.account_security.max_failed_logins' => 3,
            'tracepharma.account_security.lockout_minutes' => 15,
        ]);

        $this->initializeDemo2Tenant();
        Notification::fake();

        $email = 'portal-sec-'.Str::lower(Str::random(8)).'@example.com';
        RateLimiter::clear('portal-otp-issue:'.$email);

        $user = PortalUser::query()->create([
            'email' => $email,
            'is_active' => true,
        ]);
        $this->portalUserIds[] = (int) $user->getKey();

        $otp = app(PortalOtpService::class);
        $otp->issue($email);

        $code = null;
        Notification::assertSentOnDemand(
            PortalOtpNotification::class,
            function ($notification) use (&$code): bool {
                $code = $notification->code;

                return true;
            },
        );

        for ($i = 0; $i < 3; $i++) {
            try {
                $otp->verify($email, '000000');
            } catch (ValidationException) {
                // expected
            }
        }

        $user = $user->fresh();
        $this->assertTrue($user->isLocked());

        $user->unlock();
        $user->disable('test');

        $this->expectException(ValidationException::class);
        $otp->issue($email);
    }

    #[Test]
    public function disabled_user_cannot_access_tenant_label_print_config(): void
    {
        $this->initializeDemo2Tenant();

        $user = User::factory()->create([
            'email' => 'label-'.Str::lower(Str::random(6)).'@example.com',
        ]);
        $this->userIds[] = (int) $user->getKey();

        Auth::guard('web')->login($user);
        AccountSecuritySession::bind($user);
        $user->disable('test');

        $this->get('https://'.self::DEMO2_DOMAIN.'/label-print/config')
            ->assertForbidden();

        $this->assertGuest('web');
    }

    #[Test]
    public function disabled_user_cannot_call_sanctum_api(): void
    {
        $this->initializeDemo2Tenant();

        $user = User::factory()->create([
            'email' => 'api-'.Str::lower(Str::random(6)).'@example.com',
        ]);
        $this->userIds[] = (int) $user->getKey();
        $token = $user->createToken('sec-test', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;

        // Mark unusable without SessionRevoker so the token still authenticates and
        // EnsureAccountIsUsable:sanctum is the gate under test.
        $user->forceFill([
            'is_active' => false,
            'disabled_at' => now(),
            'disabled_reason' => 'test',
        ])->saveQuietly();

        tenancy()->end();

        $this->withToken($token)
            ->getJson('https://'.self::DEMO2_DOMAIN.'/api/v1/epcis/documents')
            ->assertForbidden();
    }

    #[Test]
    public function sso_only_refuses_password_authenticate(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        TenantSettings::forTenant($tenant)->saveSsoConfig([
            'enabled' => true,
            'sso_only' => true,
            'provider' => OidcProvider::Entra->value,
            'issuer' => 'https://login.microsoftonline.com/example/v2.0',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'entra_tenant_id' => 'example',
            'jit_default_role' => TenantRole::ReceivingTechnician->value,
            'allowed_email_domains' => [],
        ])->saveQuietly();

        try {
            $user = User::factory()->create([
                'email' => 'sso-only-'.Str::lower(Str::random(6)).'@example.com',
                'password' => 'password',
            ]);
            $this->userIds[] = (int) $user->getKey();

            $this->get('https://'.self::DEMO2_DOMAIN.'/login');
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(AppLogin::class)
                ->fillForm([
                    'email' => $user->email,
                    'password' => 'password',
                ])
                ->call('authenticate')
                ->assertHasErrors(['data.email']);

            $this->assertGuest('web');
        } finally {
            TenantSettings::forTenant($tenant)->saveSsoConfig([
                'enabled' => false,
                'sso_only' => false,
            ])->saveQuietly();
        }
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = $this->ensureDemo2Tenant();

        tenancy()->initialize($tenant);
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        config(['app.url' => 'https://'.self::DEMO2_DOMAIN]);

        // Password-auth tests must not be short-circuited by demo2 SSO pollution.
        $sso = TenantSettings::forTenant($tenant)->ssoConfig();
        if (($sso['enabled'] ?? false) || ($sso['sso_only'] ?? false)) {
            TenantSettings::forTenant($tenant)->saveSsoConfig([
                'enabled' => false,
                'sso_only' => false,
            ])->saveQuietly();
        }

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

            $this->artisan('migrate', ['--force' => true])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        return $tenant->fresh();
    }
}
