<?php

declare(strict_types=1);

namespace App\Services\Auth\Oidc;

use GuzzleHttp\RequestOptions;
use Illuminate\Support\Arr;
use SocialiteProviders\Manager\OAuth2\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User;

/**
 * Generic OpenID Connect provider driven by issuer discovery
 * ({issuer}/.well-known/openid-configuration).
 */
class GenericOpenIdConnectProvider extends AbstractProvider
{
    public const IDENTIFIER = 'GENERIC_OIDC';

    protected $scopeSeparator = ' ';

    protected $scopes = ['openid', 'profile', 'email'];

    /** @var array<string, mixed>|null */
    private ?array $discovery = null;

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->discovery()['authorization_endpoint'], $state);
    }

    protected function getTokenUrl(): string
    {
        return (string) $this->discovery()['token_endpoint'];
    }

    protected function getUserByToken($token)
    {
        $userInfo = (string) ($this->discovery()['userinfo_endpoint'] ?? '');

        if ($userInfo === '') {
            return [];
        }

        $response = $this->getHttpClient()->get($userInfo, [
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ],
        ]);

        return json_decode((string) $response->getBody(), true) ?: [];
    }

    protected function mapUserToObject(array $user)
    {
        return (new User)->setRaw($user)->map([
            'id' => Arr::get($user, 'sub'),
            'nickname' => Arr::get($user, 'preferred_username'),
            'name' => Arr::get($user, 'name'),
            'email' => Arr::get($user, 'email'),
            'avatar' => Arr::get($user, 'picture'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function discovery(): array
    {
        if ($this->discovery !== null) {
            return $this->discovery;
        }

        $issuer = rtrim((string) $this->getConfig('issuer', ''), '/');

        if ($issuer === '') {
            throw new \RuntimeException('Generic OIDC issuer is not configured.');
        }

        $response = $this->getHttpClient()->get($issuer.'/.well-known/openid-configuration', [
            RequestOptions::HEADERS => ['Accept' => 'application/json'],
        ]);

        $this->discovery = json_decode((string) $response->getBody(), true) ?: [];

        if (! isset($this->discovery['authorization_endpoint'], $this->discovery['token_endpoint'])) {
            throw new \RuntimeException('OIDC discovery document is incomplete for '.$issuer);
        }

        return $this->discovery;
    }
}
