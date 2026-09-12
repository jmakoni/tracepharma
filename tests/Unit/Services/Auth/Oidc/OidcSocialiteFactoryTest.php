<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth\Oidc;

use App\Services\Auth\Oidc\OidcSocialiteFactory;
use App\Support\Auth\OidcConnectionConfig;
use App\Support\Auth\OidcProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OidcSocialiteFactoryTest extends TestCase
{
    #[Test]
    public function entra_without_directory_id_is_not_configured(): void
    {
        $config = $this->entraConfig(entraTenantId: null);

        $this->assertFalse($config->isConfigured());
        $this->assertNull($config->pinnedEntraTenantId());
    }

    #[Test]
    public function entra_common_alias_is_not_a_pinned_directory(): void
    {
        foreach (OidcConnectionConfig::ENTRA_MULTI_TENANT_ALIASES as $alias) {
            $config = $this->entraConfig(entraTenantId: $alias);
            $this->assertFalse($config->isConfigured(), $alias);
            $this->assertNull($config->pinnedEntraTenantId(), $alias);
        }
    }

    #[Test]
    public function entra_with_directory_guid_is_configured(): void
    {
        $config = $this->entraConfig(entraTenantId: '11111111-2222-3333-4444-555555555555');

        $this->assertTrue($config->isConfigured());
        $this->assertSame('11111111-2222-3333-4444-555555555555', $config->pinnedEntraTenantId());
    }

    #[Test]
    public function bind_runtime_config_rejects_entra_without_pinned_directory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/specific directory|common/i');

        app(OidcSocialiteFactory::class)->bindRuntimeConfig($this->entraConfig(entraTenantId: null));
    }

    #[Test]
    public function bind_runtime_config_rejects_entra_common_alias(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/specific directory|common/i');

        app(OidcSocialiteFactory::class)->bindRuntimeConfig($this->entraConfig(entraTenantId: 'common'));
    }

    #[Test]
    public function bind_runtime_config_pins_entra_directory(): void
    {
        $directoryId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        app(OidcSocialiteFactory::class)->bindRuntimeConfig($this->entraConfig(entraTenantId: $directoryId));

        $this->assertSame($directoryId, config('services.azure.tenant'));
    }

    private function entraConfig(?string $entraTenantId): OidcConnectionConfig
    {
        return new OidcConnectionConfig(
            enabled: true,
            ssoOnly: false,
            provider: OidcProvider::Entra,
            issuer: 'https://login.microsoftonline.com/example/v2.0',
            clientId: 'client-id',
            clientSecret: 'client-secret',
            entraTenantId: $entraTenantId,
            jitDefaultRole: null,
            allowedEmailDomains: ['acme.test'],
            redirectUri: 'https://tenant.example/auth/oidc/callback',
            socialiteDriver: 'azure',
        );
    }
}
