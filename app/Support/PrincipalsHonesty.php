<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use App\Support\Custody\PrincipalCustody;

/**
 * Soft 3PL principal copy — filters are not EPC multi-client custody
 * unless optional principalCustodyEnforced is on for the tenant.
 */
final class PrincipalsHonesty
{
    public const SENTENCE = 'Principals filter lists; serials are not isolated per client.';

    public const ENFORCED_SENTENCE = 'Principal custody is enforced; serials are gated per principal.';

    public static function forTenant(?Tenant $tenant = null): self
    {
        $tenant ??= tenant();

        return new self(
            TenantFeatures::forTenant($tenant),
            PrincipalCustody::forTenant($tenant),
        );
    }

    public function __construct(
        private readonly TenantFeatures $features,
        private readonly PrincipalCustody $custody,
    ) {}

    public function shouldShow(): bool
    {
        return $this->features->supportsPrincipals();
    }

    public function sentence(): string
    {
        return $this->custody->isEnforced()
            ? self::ENFORCED_SENTENCE
            : self::SENTENCE;
    }
}
