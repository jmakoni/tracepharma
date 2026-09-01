<?php

declare(strict_types=1);

namespace App\Support\Auth;

final readonly class OidcConnectionConfig
{
    /**
     * @param  list<string>  $allowedEmailDomains
     */
    public function __construct(
        public bool $enabled,
        public bool $ssoOnly,
        public OidcProvider $provider,
        public string $issuer,
        public string $clientId,
        public string $clientSecret,
        public ?string $entraTenantId,
        public ?string $jitDefaultRole,
        public array $allowedEmailDomains,
        public string $redirectUri,
        public string $socialiteDriver,
    ) {}

    public function isConfigured(): bool
    {
        return $this->enabled
            && $this->issuer !== ''
            && $this->clientId !== ''
            && $this->clientSecret !== '';
    }
}
