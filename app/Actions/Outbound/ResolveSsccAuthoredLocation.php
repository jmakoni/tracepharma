<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Actions\Shipping\ResolveOutboundAuthoredLocation;
use App\Support\Gs1\Sgln;
use App\Support\Gs1\SglnResolution;
use App\Support\TenantSettings;
use DomainException;
use Throwable;

/**
 * Resolve readPoint/bizLocation GLN + SGLN URN for authored SSCC EPCIS events.
 *
 * Prefers ship-from site via ResolveOutboundAuthoredLocation; falls back to
 * tenant organization GLN + company prefix.
 *
 * The SGLN comes from the site's own record or from our GS1 Company Prefix, and from
 * nowhere else ({@see SglnResolution}): a commissioning event names the location that
 * brought the SSCC into existence, so an invented split would put another party's
 * dock on it.
 *
 * @return array{gln: string, sgln_urn: string}
 */
final class ResolveSsccAuthoredLocation
{
    public function __construct(
        private readonly ResolveOutboundAuthoredLocation $resolveOutboundAuthoredLocation,
    ) {}

    /**
     * @return array{gln: string, sgln_urn: string}
     */
    public function handle(?int $explicitSiteId = null): array
    {
        try {
            $location = $this->resolveOutboundAuthoredLocation->handle($explicitSiteId);
            $gln = $location['gln'];
            $sglnUrn = $this->sglnForGln($gln, $location['site']->getAttribute('sgln') ?? null);

            if ($sglnUrn !== null) {
                return ['gln' => $gln, 'sgln_urn' => $sglnUrn];
            }

            if ($explicitSiteId !== null && $explicitSiteId > 0) {
                throw new DomainException(
                    'Unable to derive a valid SGLN for the selected commission site GLN. Fix the site GLN or company prefix partitioning.',
                );
            }
        } catch (DomainException $exception) {
            if ($explicitSiteId !== null && $explicitSiteId > 0) {
                throw $exception;
            }
            // Fall through to tenant organization GLN when no explicit site was requested.
        } catch (Throwable $exception) {
            if ($explicitSiteId !== null && $explicitSiteId > 0) {
                throw $exception instanceof DomainException
                    ? $exception
                    : new DomainException(
                        'Unable to resolve commissioning location for the selected site: '.$exception->getMessage(),
                        0,
                        $exception,
                    );
            }
            // Fall through to tenant organization GLN when no explicit site was requested.
        }

        $settings = TenantSettings::forTenant(tenant());
        $gln = Sgln::normalizeGln($settings->gln()) ?? '';

        if ($gln === '') {
            throw new DomainException(
                'Configure an organization GLN (or a commission site with a GLN) before authoring SSCC EPCIS.',
            );
        }

        $sglnUrn = $this->sglnForGln($gln);

        if ($sglnUrn === null) {
            throw new DomainException(
                'Unable to derive a valid SGLN for the organization GLN. Configure a GS1 company prefix that partitions the GLN.',
            );
        }

        return [
            'gln' => $gln,
            'sgln_urn' => $sglnUrn,
        ];
    }

    private function sglnForGln(string $gln, mixed $hintUrn = null): ?string
    {
        return SglnResolution::resolve(
            $gln,
            [$hintUrn],
            TenantSettings::forTenant(tenant())->companyPrefix(),
        );
    }
}
