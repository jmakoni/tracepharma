<?php

namespace App\Support\Gs1;

use App\Models\Site;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;

/**
 * Operator-facing GS1 identity copy: derived company SGLN, partner SGLN status,
 * and the soft go-live banner when GLN or GCP is still blank.
 */
final class Gs1IdentityStatus
{
    public const MISSING_COMPANY_SGLN = 'Enter GLN and GCP — we cannot build SGLN from GLN alone.';

    public const PARTNER_MISSING = 'Missing — wait for an inbound EPCIS from this partner, or paste the SGLN from their document.';

    public const PARTNER_FROM_EPCIS = 'From their EPCIS — this URN matches the GLN.';

    public const PARTNER_PASTED = 'Pasted — this 3-segment URN encodes this GLN.';

    public const ORG_NOT_UNDER_PREFIX = 'This GLN is not under your company prefix. Paste the facility SGLN from GS1, or correct the GLN.';

    public static function companySglnPreview(?string $gln, ?string $companyPrefix): string
    {
        return self::derivedCompanySgln($gln, $companyPrefix) ?? self::MISSING_COMPANY_SGLN;
    }

    public static function derivedCompanySgln(?string $gln, ?string $companyPrefix): ?string
    {
        return SglnResolution::fromCompanyPrefix($gln, $companyPrefix);
    }

    public static function identityIncomplete(): bool
    {
        $features = TenantFeatures::forTenant(tenant());

        if (! $features->supportsPacking()
            && ! $features->supportsSsccLabeling()
            && ! $features->canAuthorOutboundShipments()) {
            return false;
        }

        $settings = TenantSettings::forTenant(tenant());

        return blank($settings->gln()) || blank($settings->companyPrefix());
    }

    public static function resolveOrgSiteSgln(?string $gln): ?string
    {
        $probe = new Site([
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);

        return SglnResolution::resolve(
            $gln,
            [],
            TenantSettings::forTenant(tenant())->companyPrefix(),
            OrganizationSglnPrefixes::forSite($probe),
        );
    }

    public static function canDeriveOrgSiteSgln(?string $gln): bool
    {
        return self::resolveOrgSiteSgln($gln) !== null;
    }

    public static function orgSiteSglnHelper(?string $gln): string
    {
        $derived = self::resolveOrgSiteSgln($gln);

        if ($derived !== null) {
            return 'Derived from this GLN and your company prefix: '.$derived;
        }

        $normalized = Sgln::normalizeGln($gln);

        if ($normalized !== null) {
            return self::ORG_NOT_UNDER_PREFIX;
        }

        return 'Enter a 13-digit GLN. SGLN is derived when that GLN sits under your company prefix.';
    }

    public static function partnerSglnStatus(?string $sgln, ?string $gln, ?string $recordedSgln = null): string
    {
        $normalized = Sgln::normalizeGln($gln);
        $parsed = is_string($sgln) && $sgln !== '' ? Sgln::fromUrn($sgln) : null;

        if ($parsed === null || $normalized === null || $parsed['gln'] !== $normalized) {
            return self::PARTNER_MISSING;
        }

        if (is_string($recordedSgln) && $recordedSgln !== '' && $sgln === $recordedSgln) {
            return self::PARTNER_FROM_EPCIS;
        }

        return self::PARTNER_PASTED;
    }

    public static function missingSglnAfterRegisterBody(): string
    {
        return 'SGLN is still missing — ship/receive authoring will wait for an inbound EPCIS or a pasted URN.';
    }
}
