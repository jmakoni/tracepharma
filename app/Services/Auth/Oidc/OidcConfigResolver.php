<?php

declare(strict_types=1);

namespace App\Services\Auth\Oidc;

use App\Support\Auth\OidcConnectionConfig;
use App\Support\Auth\OidcProvider;
use App\Support\PlatformSettings;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

final class OidcConfigResolver
{
    public function forCurrentTenant(): ?OidcConnectionConfig
    {
        if (! tenancy()->initialized) {
            return null;
        }

        $tenant = tenant();
        if ($tenant === null) {
            return null;
        }

        return $this->fromTenantSettings(
            TenantSettings::forTenant($tenant),
            URL::route('tenant.oidc.callback', absolute: true),
        );
    }

    public function forAdmin(): ?OidcConnectionConfig
    {
        return $this->fromPlatformSettings(
            URL::route('admin.oidc.callback', absolute: true),
        );
    }

    public function fromTenantSettings(TenantSettings $settings, string $redirectUri): ?OidcConnectionConfig
    {
        $bag = $settings->ssoConfig();

        return $this->build($bag, $redirectUri, includeJit: true);
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    public function fromPlatformSettings(string $redirectUri): ?OidcConnectionConfig
    {
        $bag = [
            'enabled' => PlatformSettings::ssoAdminEnabled(),
            'sso_only' => PlatformSettings::ssoAdminOnly(),
            'provider' => PlatformSettings::ssoAdminProvider(),
            'issuer' => PlatformSettings::ssoAdminIssuer(),
            'client_id' => PlatformSettings::ssoAdminClientId(),
            'client_secret' => PlatformSettings::ssoAdminClientSecret(),
            'entra_tenant_id' => PlatformSettings::ssoAdminEntraTenantId(),
            'jit_default_role' => null,
            'allowed_email_domains' => [],
        ];

        return $this->build($bag, $redirectUri, includeJit: false);
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private function build(array $bag, string $redirectUri, bool $includeJit): ?OidcConnectionConfig
    {
        $providerValue = is_string($bag['provider'] ?? null) ? $bag['provider'] : OidcProvider::Entra->value;
        $provider = OidcProvider::tryFrom($providerValue) ?? OidcProvider::Entra;

        $issuer = trim((string) ($bag['issuer'] ?? ''));
        $clientId = trim((string) ($bag['client_id'] ?? ''));
        $clientSecret = (string) ($bag['client_secret'] ?? '');
        $entraTenantId = filled($bag['entra_tenant_id'] ?? null)
            ? trim((string) $bag['entra_tenant_id'])
            : null;

        $domains = [];
        if ($includeJit && is_array($bag['allowed_email_domains'] ?? null)) {
            foreach ($bag['allowed_email_domains'] as $domain) {
                if (is_string($domain) && trim($domain) !== '') {
                    $domains[] = Str::lower(trim($domain));
                }
            }
        }

        return new OidcConnectionConfig(
            enabled: (bool) ($bag['enabled'] ?? false),
            ssoOnly: (bool) ($bag['sso_only'] ?? false),
            provider: $provider,
            issuer: $issuer,
            clientId: $clientId,
            clientSecret: $clientSecret,
            entraTenantId: $entraTenantId,
            jitDefaultRole: $includeJit && filled($bag['jit_default_role'] ?? null)
                ? (string) $bag['jit_default_role']
                : null,
            allowedEmailDomains: $domains,
            redirectUri: $redirectUri,
            socialiteDriver: match ($provider) {
                OidcProvider::Entra => 'azure',
                OidcProvider::Okta => 'okta',
                OidcProvider::Oidc => 'generic-oidc',
            },
        );
    }
}
