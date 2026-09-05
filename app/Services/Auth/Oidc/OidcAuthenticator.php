<?php

declare(strict_types=1);

namespace App\Services\Auth\Oidc;

use App\Models\Concerns\HasAccountSecurity;
use App\Support\Auth\AccountSecuritySession;
use App\Support\Auth\OidcConnectionConfig;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

final class OidcAuthenticator
{
    public function __construct(
        private readonly OidcConfigResolver $configs,
        private readonly OidcSocialiteFactory $socialite,
        private readonly OidcState $state,
        private readonly OidcIdentityResolver $identities,
    ) {}

    public function redirectForTenant(): SymfonyRedirect
    {
        $config = $this->requireTenantConfig();

        $nonce = $this->state->freshNonce();

        $this->state->put([
            'plane' => 'tenant',
            'tenant_id' => (string) tenant()->getTenantKey(),
            'provider' => $config->provider->value,
            'nonce' => $nonce,
        ]);

        return $this->socialite->make($config, $nonce)->redirect();
    }

    public function redirectForAdmin(): SymfonyRedirect
    {
        $config = $this->requireAdminConfig();

        $nonce = $this->state->freshNonce();

        $this->state->put([
            'plane' => 'admin',
            'tenant_id' => null,
            'provider' => $config->provider->value,
            'nonce' => $nonce,
        ]);

        return $this->socialite->make($config, $nonce)->redirect();
    }

    public function handleTenantCallback(): RedirectResponse
    {
        $config = $this->requireTenantConfig();
        $payload = $this->state->pull();

        if ($payload['plane'] !== 'tenant') {
            throw new \InvalidArgumentException('OIDC state plane mismatch.');
        }

        if ((string) tenant()->getTenantKey() !== (string) $payload['tenant_id']) {
            throw new \InvalidArgumentException('OIDC state tenant mismatch.');
        }

        if ($payload['provider'] !== $config->provider->value) {
            throw new \InvalidArgumentException('OIDC state provider mismatch.');
        }

        $socialiteUser = $this->socialite->make($config)->user();
        app(OidcIdTokenValidator::class)->assertNonceMatches($socialiteUser, $payload['nonce']);
        $user = $this->identities->resolveTenantUser($socialiteUser, $config);

        return $this->loginAndRedirect($user, 'web', 'app');
    }

    public function handleAdminCallback(): RedirectResponse
    {
        $config = $this->requireAdminConfig();
        $payload = $this->state->pull();

        if ($payload['plane'] !== 'admin') {
            throw new \InvalidArgumentException('OIDC state plane mismatch.');
        }

        if ($payload['provider'] !== $config->provider->value) {
            throw new \InvalidArgumentException('OIDC state provider mismatch.');
        }

        $socialiteUser = $this->socialite->make($config)->user();
        app(OidcIdTokenValidator::class)->assertNonceMatches($socialiteUser, $payload['nonce']);
        $admin = $this->identities->resolveAdmin($socialiteUser, $config);

        return $this->loginAndRedirect($admin, 'admin', 'admin');
    }

    public function tenantConfig(): ?OidcConnectionConfig
    {
        return $this->configs->forCurrentTenant();
    }

    public function adminConfig(): ?OidcConnectionConfig
    {
        return $this->configs->forAdmin();
    }

    private function requireTenantConfig(): OidcConnectionConfig
    {
        $config = $this->configs->forCurrentTenant();

        if ($config === null || ! $config->isConfigured()) {
            throw new \RuntimeException('Tenant SSO is not configured.');
        }

        return $config;
    }

    private function requireAdminConfig(): OidcConnectionConfig
    {
        $config = $this->configs->forAdmin();

        if ($config === null || ! $config->isConfigured()) {
            throw new \RuntimeException('Admin SSO is not configured.');
        }

        return $config;
    }

    private function loginAndRedirect(
        Authenticatable $user,
        string $guard,
        string $panelId,
    ): RedirectResponse {
        if (in_array(HasAccountSecurity::class, class_uses_recursive($user), true) && ! $user->isUsable()) {
            /** @phpstan-ignore-next-line */
            abort(403, $user->authenticationFailureMessage());
        }

        Auth::guard($guard)->login($user, remember: true);

        session(['auth.via' => 'oidc']);
        AccountSecuritySession::bind($user);

        Filament::setCurrentPanel(Filament::getPanel($panelId));

        return redirect()->intended(Filament::getUrl());
    }
}
