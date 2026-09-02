<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Oidc\OidcIdentityResolver;
use App\Services\Auth\Oidc\OidcState;
use App\Support\Auth\OidcConnectionConfig;
use App\Support\Auth\OidcProvider;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\PlatformSettings;
use App\Support\TenantSettings;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\User as SocialiteUser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OidcSsoTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function tenant_jit_creates_user_and_assigns_default_role(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->saveSsoConfig([
                'enabled' => true,
                'sso_only' => false,
                'provider' => OidcProvider::Entra->value,
                'issuer' => 'https://login.microsoftonline.com/example/v2.0',
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
                'entra_tenant_id' => 'example',
                'jit_default_role' => TenantRole::ReceivingTechnician->value,
                'allowed_email_domains' => ['acme.test'],
            ]);
            $tenant->save();

            $config = new OidcConnectionConfig(
                enabled: true,
                ssoOnly: false,
                provider: OidcProvider::Entra,
                issuer: 'https://login.microsoftonline.com/example/v2.0',
                clientId: 'client-id',
                clientSecret: 'client-secret',
                entraTenantId: 'example',
                jitDefaultRole: TenantRole::ReceivingTechnician->value,
                allowedEmailDomains: ['acme.test'],
                redirectUri: 'https://'.self::DEMO2_DOMAIN.'/auth/oidc/callback',
                socialiteDriver: 'azure',
            );

            $socialite = (new SocialiteUser)->map([
                'id' => 'oid-sub-1',
                'name' => 'SSO User',
                'email' => 'sso.user@acme.test',
            ]);

            $user = app(OidcIdentityResolver::class)->resolveTenantUser($socialite, $config);

            $this->assertSame('sso.user@acme.test', $user->email);
            $this->assertSame('oid-sub-1', $user->oidc_subject);
            $this->assertTrue($user->hasRole(TenantRole::ReceivingTechnician->value));
            $this->assertNull($user->password);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function tenant_jit_rejects_disallowed_email_domain(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $config = new OidcConnectionConfig(
                enabled: true,
                ssoOnly: false,
                provider: OidcProvider::Entra,
                issuer: 'https://login.microsoftonline.com/example/v2.0',
                clientId: 'client-id',
                clientSecret: 'client-secret',
                entraTenantId: 'example',
                jitDefaultRole: TenantRole::ReceivingTechnician->value,
                allowedEmailDomains: ['acme.test'],
                redirectUri: 'https://'.self::DEMO2_DOMAIN.'/auth/oidc/callback',
                socialiteDriver: 'azure',
            );

            $socialite = (new SocialiteUser)->map([
                'id' => 'oid-sub-2',
                'name' => 'Bad Domain',
                'email' => 'user@other.test',
            ]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Email domain is not allowed');

            app(OidcIdentityResolver::class)->resolveTenantUser($socialite, $config);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function tenant_jit_rejects_when_allowed_email_domains_empty(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $config = new OidcConnectionConfig(
                enabled: true,
                ssoOnly: false,
                provider: OidcProvider::Entra,
                issuer: 'https://login.microsoftonline.com/example/v2.0',
                clientId: 'client-id',
                clientSecret: 'client-secret',
                entraTenantId: 'example',
                jitDefaultRole: TenantRole::ReceivingTechnician->value,
                allowedEmailDomains: [],
                redirectUri: 'https://'.self::DEMO2_DOMAIN.'/auth/oidc/callback',
                socialiteDriver: 'azure',
            );

            $socialite = (new SocialiteUser)->map([
                'id' => 'oid-sub-empty-domains',
                'name' => 'No Allowlist',
                'email' => 'user@acme.test',
            ]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('SSO allowed email domains must be configured');

            app(OidcIdentityResolver::class)->resolveTenantUser($socialite, $config);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function tenant_sso_rejects_existing_user_when_email_domain_is_not_allowed(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $email = 'bob-'.Str::uuid()->toString().'@legacy.test';

            User::factory()->create([
                'email' => $email,
                'password' => 'password',
            ]);

            $config = new OidcConnectionConfig(
                enabled: true,
                ssoOnly: false,
                provider: OidcProvider::Entra,
                issuer: 'https://login.microsoftonline.com/example/v2.0',
                clientId: 'client-id',
                clientSecret: 'client-secret',
                entraTenantId: 'example',
                jitDefaultRole: TenantRole::ReceivingTechnician->value,
                allowedEmailDomains: ['acme.test'],
                redirectUri: 'https://'.self::DEMO2_DOMAIN.'/auth/oidc/callback',
                socialiteDriver: 'azure',
            );

            $socialite = (new SocialiteUser)->map([
                'id' => 'attacker-sub',
                'name' => 'Attacker',
                'email' => $email,
            ]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Email domain is not allowed');

            app(OidcIdentityResolver::class)->resolveTenantUser($socialite, $config);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function tenant_sso_rejects_subject_mismatch_on_existing_bound_user(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $issuer = 'https://login.microsoftonline.com/example/v2.0';
            $email = 'linked-'.Str::uuid()->toString().'@acme.test';

            $subject = 'victim-subject-'.Str::uuid()->toString();

            User::factory()->create([
                'email' => $email,
                'password' => 'password',
                'oidc_issuer' => $issuer,
                'oidc_subject' => $subject,
            ]);
            $this->userIds[] = (int) User::query()->where('email', $email)->value('id');

            $config = new OidcConnectionConfig(
                enabled: true,
                ssoOnly: false,
                provider: OidcProvider::Entra,
                issuer: $issuer,
                clientId: 'client-id',
                clientSecret: 'client-secret',
                entraTenantId: 'example',
                jitDefaultRole: TenantRole::ReceivingTechnician->value,
                allowedEmailDomains: ['acme.test'],
                redirectUri: 'https://'.self::DEMO2_DOMAIN.'/auth/oidc/callback',
                socialiteDriver: 'azure',
            );

            $socialite = (new SocialiteUser)->map([
                'id' => 'attacker-subject-'.Str::uuid()->toString(),
                'name' => 'Attacker',
                'email' => $email,
            ]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('OIDC identity subject does not match the existing account binding.');

            app(OidcIdentityResolver::class)->resolveTenantUser($socialite, $config);
        } finally {
            $this->cleanupUsers();
            tenancy()->end();
        }
    }

    #[Test]
    public function tenant_sso_links_password_user_without_existing_oidc_binding(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $issuer = 'https://login.microsoftonline.com/example/v2.0';
            $email = 'password-only-'.Str::uuid()->toString().'@acme.test';

            $existing = User::factory()->create([
                'email' => $email,
                'password' => 'password',
            ]);
            $this->userIds[] = (int) $existing->getKey();

            $config = new OidcConnectionConfig(
                enabled: true,
                ssoOnly: false,
                provider: OidcProvider::Entra,
                issuer: $issuer,
                clientId: 'client-id',
                clientSecret: 'client-secret',
                entraTenantId: 'example',
                jitDefaultRole: TenantRole::ReceivingTechnician->value,
                allowedEmailDomains: ['acme.test'],
                redirectUri: 'https://'.self::DEMO2_DOMAIN.'/auth/oidc/callback',
                socialiteDriver: 'azure',
            );

            $socialite = (new SocialiteUser)->map([
                'id' => 'new-oidc-subject-'.Str::uuid()->toString(),
                'name' => 'Linked User',
                'email' => $email,
            ]);

            $user = app(OidcIdentityResolver::class)->resolveTenantUser($socialite, $config);

            $this->assertTrue($user->is($existing->fresh()));
            $this->assertSame($socialite->getId(), $user->oidc_subject);
            $this->assertSame($issuer, $user->oidc_issuer);
        } finally {
            $this->cleanupUsers();
            tenancy()->end();
        }
    }

    #[Test]
    public function admin_sso_requires_pre_provisioned_admin(): void
    {
        $config = new OidcConnectionConfig(
            enabled: true,
            ssoOnly: false,
            provider: OidcProvider::Entra,
            issuer: 'https://login.microsoftonline.com/example/v2.0',
            clientId: 'client-id',
            clientSecret: 'client-secret',
            entraTenantId: 'example',
            jitDefaultRole: null,
            allowedEmailDomains: [],
            redirectUri: 'https://admin.example/auth/oidc/callback',
            socialiteDriver: 'azure',
        );

        $socialite = (new SocialiteUser)->map([
            'id' => 'admin-sub-1',
            'name' => 'Unknown Admin',
            'email' => 'unknown.admin@tracepharma.test',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No platform admin account is provisioned');

        app(OidcIdentityResolver::class)->resolveAdmin($socialite, $config);
    }

    #[Test]
    public function oidc_state_rejects_missing_session_context(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or invalid OIDC login context');

        app(OidcState::class)->pull();
    }

    #[Test]
    public function oidc_state_round_trips_and_is_single_use(): void
    {
        $state = app(OidcState::class);
        $state->put([
            'plane' => 'tenant',
            'tenant_id' => self::DEMO2_TENANT_ID,
            'provider' => 'entra',
            'nonce' => 'abc',
        ]);

        $payload = $state->pull();
        $this->assertSame('tenant', $payload['plane']);
        $this->assertSame(self::DEMO2_TENANT_ID, $payload['tenant_id']);

        $this->expectException(\InvalidArgumentException::class);
        $state->pull();
    }

    #[Test]
    public function tenant_login_hides_password_when_sso_only(): void
    {
        $tenant = $this->ensureDemo2Tenant();

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
        ]);
        $tenant->save();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->get('https://'.self::DEMO2_DOMAIN.'/login', [
            'HTTP_HOST' => self::DEMO2_DOMAIN,
        ])
            ->assertOk()
            ->assertSee('Sign In With Microsoft Entra ID')
            ->assertDontSee('wire:model="data.password"', false);
    }

    #[Test]
    public function admin_login_shows_sso_button_when_configured(): void
    {
        PlatformSettings::saveSsoAdminConfig([
            'enabled' => true,
            'sso_only' => false,
            'provider' => OidcProvider::Okta->value,
            'issuer' => 'https://example.okta.com',
            'client_id' => 'admin-client',
            'client_secret' => 'admin-secret',
            'entra_tenant_id' => null,
        ]);

        $adminHost = (string) config('tracepharma.admin_domain');

        $this->get('http://'.$adminHost.'/login', [
            'HTTP_HOST' => $adminHost,
        ])
            ->assertOk()
            ->assertSee('Sign In With Okta');
    }

    #[Test]
    public function admin_sso_requires_prior_oidc_binding(): void
    {
        $subject = 'admin-sub-'.Str::uuid()->toString();
        $issuer = 'https://example.okta.com/'.Str::uuid()->toString();

        $admin = Admin::factory()->create([
            'email' => 'oidc-admin-'.Str::uuid()->toString().'@tracepharma.test',
            'oidc_issuer' => $issuer,
            'oidc_subject' => $subject,
        ]);

        $config = new OidcConnectionConfig(
            enabled: true,
            ssoOnly: false,
            provider: OidcProvider::Okta,
            issuer: $issuer,
            clientId: 'client-id',
            clientSecret: 'client-secret',
            entraTenantId: null,
            jitDefaultRole: null,
            allowedEmailDomains: [],
            redirectUri: 'https://admin.example/auth/oidc/callback',
            socialiteDriver: 'okta',
        );

        $socialite = (new SocialiteUser)->map([
            'id' => $subject,
            'name' => 'Existing Admin',
            'email' => $admin->email,
        ]);

        $resolved = app(OidcIdentityResolver::class)->resolveAdmin($socialite, $config);

        $this->assertTrue($resolved->is($admin->fresh()));
        $this->assertSame($subject, $resolved->oidc_subject);
        $this->assertSame($issuer, $resolved->oidc_issuer);
    }

    #[Test]
    public function admin_sso_rejects_unbound_account(): void
    {
        Admin::factory()->create([
            'email' => 'unbound-admin-'.Str::uuid()->toString().'@tracepharma.test',
        ]);

        $config = new OidcConnectionConfig(
            enabled: true,
            ssoOnly: false,
            provider: OidcProvider::Okta,
            issuer: 'https://example.okta.com/'.Str::uuid()->toString(),
            clientId: 'client-id',
            clientSecret: 'client-secret',
            entraTenantId: null,
            jitDefaultRole: null,
            allowedEmailDomains: [],
            redirectUri: 'https://admin.example/auth/oidc/callback',
            socialiteDriver: 'okta',
        );

        $socialite = (new SocialiteUser)->map([
            'id' => 'new-subject',
            'name' => 'Unknown Admin',
            'email' => 'other-'.Str::uuid()->toString().'@tracepharma.test',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No platform admin account is provisioned');

        app(OidcIdentityResolver::class)->resolveAdmin($socialite, $config);
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = $this->ensureDemo2Tenant();

        tenancy()->initialize($tenant);
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

            $this->artisan('migrate', ['--force' => true])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        return $tenant->fresh();
    }

    private function cleanupUsers(): void
    {
        if (! tenancy()->initialized || $this->userIds === []) {
            $this->userIds = [];

            return;
        }

        User::query()->whereIn('id', $this->userIds)->delete();
        $this->userIds = [];
    }
}
