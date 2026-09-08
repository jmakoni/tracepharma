<?php

declare(strict_types=1);

namespace App\Support\Auth;

final readonly class OidcConnectionConfig
{
    /**
     * Multi-tenant Entra aliases that accept tokens from any directory.
     *
     * @var list<string>
     */
    public const ENTRA_MULTI_TENANT_ALIASES = ['common', 'organizations', 'consumers'];

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
        if (! $this->enabled
            || $this->issuer === ''
            || $this->clientId === ''
            || $this->clientSecret === '') {
            return false;
        }

        if ($this->provider === OidcProvider::Entra) {
            return $this->pinnedEntraTenantId() !== null;
        }

        return true;
    }

    /**
     * Specific Entra directory GUID/name — never common/organizations/consumers.
     */
    public function pinnedEntraTenantId(): ?string
    {
        if ($this->provider !== OidcProvider::Entra) {
            return null;
        }

        $id = trim((string) $this->entraTenantId);
        if ($id === '') {
            return null;
        }

        if (in_array(strtolower($id), self::ENTRA_MULTI_TENANT_ALIASES, true)) {
            return null;
        }

        return $id;
    }
}
