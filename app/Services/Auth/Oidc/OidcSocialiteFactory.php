<?php

declare(strict_types=1);

namespace App\Services\Auth\Oidc;

use App\Support\Auth\OidcConnectionConfig;
use App\Support\Auth\OidcProvider;
use Illuminate\Support\Facades\Config;
use Laravel\Socialite\Contracts\Provider as SocialiteProviderContract;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Manager\Config as SocialiteConfig;

final class OidcSocialiteFactory
{
    public function make(OidcConnectionConfig $config): SocialiteProviderContract
    {
        $this->bindRuntimeConfig($config);

        $driver = Socialite::driver($config->socialiteDriver);

        if (method_exists($driver, 'setConfig')) {
            $driver->setConfig($this->socialiteConfig($config));
        }

        return $driver->scopes($this->scopes($config->provider));
    }

    public function bindRuntimeConfig(OidcConnectionConfig $config): void
    {
        $payload = match ($config->provider) {
            OidcProvider::Entra => [
                'client_id' => $config->clientId,
                'client_secret' => $config->clientSecret,
                'redirect' => $config->redirectUri,
                'tenant' => $config->entraTenantId ?: 'common',
            ],
            OidcProvider::Okta => [
                'client_id' => $config->clientId,
                'client_secret' => $config->clientSecret,
                'redirect' => $config->redirectUri,
                'base_url' => rtrim($config->issuer, '/'),
            ],
            OidcProvider::Oidc => [
                'client_id' => $config->clientId,
                'client_secret' => $config->clientSecret,
                'redirect' => $config->redirectUri,
                'issuer' => rtrim($config->issuer, '/'),
            ],
        };

        Config::set('services.'.$config->socialiteDriver, $payload);
    }

    private function socialiteConfig(OidcConnectionConfig $config): SocialiteConfig
    {
        $additional = match ($config->provider) {
            OidcProvider::Entra => ['tenant' => $config->entraTenantId ?: 'common'],
            OidcProvider::Okta => ['base_url' => rtrim($config->issuer, '/')],
            OidcProvider::Oidc => ['issuer' => rtrim($config->issuer, '/')],
        };

        return new SocialiteConfig(
            $config->clientId,
            $config->clientSecret,
            $config->redirectUri,
            $additional,
        );
    }

    /**
     * @return list<string>
     */
    private function scopes(OidcProvider $provider): array
    {
        return match ($provider) {
            OidcProvider::Entra => ['openid', 'profile', 'email', 'User.Read'],
            OidcProvider::Okta, OidcProvider::Oidc => ['openid', 'profile', 'email'],
        };
    }
}
