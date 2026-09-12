<?php

declare(strict_types=1);

namespace App\Support\Shipping;

use App\Models\Principal;
use App\Models\Site;
use App\Support\Custody\PrincipalCustody;
use App\Support\Gs1\Sgln;
use App\Support\TenantFeatures;
use DomainException;

/**
 * Resolve WMS ship-confirm principal from payload and/or site default.
 */
final class ResolveWmsShipPrincipal
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, ?int $siteId): ?int
    {
        if (! TenantFeatures::forTenant(tenant())->supportsPrincipals()) {
            return null;
        }

        $fromPayload = $this->fromPayload($payload);
        $fromSite = $this->fromSite($siteId);
        $enforced = PrincipalCustody::forTenant()->isEnforced();

        if ($fromPayload !== null && $fromSite !== null && $fromPayload !== $fromSite) {
            throw new DomainException(
                'WMS principal does not match the ship-from site default principal.',
            );
        }

        $resolved = $fromPayload ?? $fromSite;

        if ($enforced && ($resolved === null || $resolved <= 0)) {
            throw new DomainException(
                'Principal custody is enforced — provide principal_external_ref or principal_gln (or set the site default principal).',
            );
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fromPayload(array $payload): ?int
    {
        $externalRef = isset($payload['principal_external_ref'])
            ? trim((string) $payload['principal_external_ref'])
            : '';
        $gln = isset($payload['principal_gln'])
            ? Sgln::normalizeGln((string) $payload['principal_gln'])
            : null;
        $explicitId = isset($payload['principal_id']) && $payload['principal_id'] !== null && $payload['principal_id'] !== ''
            ? (int) $payload['principal_id']
            : null;

        $matches = [];

        if ($explicitId !== null && $explicitId > 0) {
            $matches[] = $explicitId;
        }

        if ($externalRef !== '') {
            $id = Principal::query()
                ->where('is_active', true)
                ->where('external_ref', $externalRef)
                ->value('id');
            if ($id === null) {
                throw new DomainException(
                    'Unknown or inactive principal_external_ref: '.$externalRef,
                );
            }
            $matches[] = (int) $id;
        }

        if ($gln !== null) {
            $id = Principal::query()
                ->where('is_active', true)
                ->where('gln', $gln)
                ->value('id');
            if ($id === null) {
                throw new DomainException(
                    'Unknown or inactive principal_gln: '.$gln,
                );
            }
            $matches[] = (int) $id;
        }

        $matches = array_values(array_unique($matches));

        if (count($matches) > 1) {
            throw new DomainException(
                'Conflicting principal identifiers in the WMS ship-confirm payload.',
            );
        }

        return $matches[0] ?? null;
    }

    private function fromSite(?int $siteId): ?int
    {
        if ($siteId === null || $siteId <= 0) {
            return null;
        }

        $fromSite = Site::query()->whereKey($siteId)->value('principal_id');

        return $fromSite !== null ? (int) $fromSite : null;
    }
}
