<?php

namespace App\Support\MasterData;

use App\Filament\App\Support\FdaPicker;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaWddFacility;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Rules\RejectPartnerGlnUnderOrgPrefix;
use App\Rules\RejectTenantGln;
use App\Rules\ValidGln;
use App\Support\Catalog\DisplayName;
use App\Support\Fda\FdaPrefill;
use App\Support\Fda\FdaTenantLink;
use DomainException;

/**
 * Partner-scoped site create helpers (FDA pick vs manual).
 */
final class PartnerSiteCreate
{
    public const MODE_FDA = 'fda';

    public const MODE_MANUAL = 'manual';

    public static function defaultCreateMode(TradingPartner $partner): string
    {
        return self::hasFdaLink($partner) ? self::MODE_FDA : self::MODE_MANUAL;
    }

    public static function hasFdaLink(TradingPartner $partner): bool
    {
        return FdaTenantLink::organizationId($partner) !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws DomainException when the picked FDA site cannot be mirrored here
     */
    public static function resolveCreateData(TradingPartner $partner, array $data): array
    {
        $pick = is_string($data['fda_pick'] ?? null) ? $data['fda_pick'] : null;
        unset($data['create_mode'], $data['fda_pick']);

        $data['trading_partner_id'] = $partner->getKey();

        $parsed = FdaPicker::parseTradingPartnerPick($pick);

        if ($parsed !== null && $parsed['type'] === 'est') {
            $data['fda_establishment_id'] = $parsed['id'];
            unset($data['fda_wdd_facility_id']);
        } elseif ($parsed !== null && $parsed['type'] === 'wdd') {
            $data['fda_wdd_facility_id'] = $parsed['id'];
            unset($data['fda_establishment_id']);
        }

        if (filled($data['fda_wdd_facility_id'] ?? null)) {
            $facility = FdaWddFacility::query()->find($data['fda_wdd_facility_id']);
            $problem = self::wddFacilityProblemFor($partner, $facility);

            if ($problem !== null) {
                throw new DomainException($problem);
            }

            $data = FdaPrefill::mergeKeepingFilledGln($data, FdaPrefill::wddFacilityAttributes($facility));
        } elseif (filled($data['fda_establishment_id'] ?? null)) {
            $establishment = FdaEstablishment::query()->find($data['fda_establishment_id']);
            $problem = self::establishmentProblemFor($partner, $establishment);

            if ($problem !== null) {
                throw new DomainException($problem);
            }

            $data = FdaPrefill::mergeKeepingFilledGln($data, FdaPrefill::establishmentAttributes($establishment));
        }

        return Site::syncOrganizationFacilityFlag($data);
    }

    public static function wddFacilityProblemFor(TradingPartner $partner, ?FdaWddFacility $facility): ?string
    {
        if ($facility === null) {
            return 'That WDD facility no longer exists. Reopen the form and pick again.';
        }

        $organizationId = FdaTenantLink::organizationId($partner);

        if ($organizationId !== null && (int) $facility->fda_organization_id !== $organizationId) {
            return 'That WDD facility belongs to a different organization.';
        }

        return self::glnAndCodeProblems($facility->gln, $facility->code);
    }

    public static function establishmentProblemFor(TradingPartner $partner, ?FdaEstablishment $establishment): ?string
    {
        if ($establishment === null) {
            return 'That FDA establishment no longer exists. Reopen the form and pick again.';
        }

        $organizationId = FdaTenantLink::organizationId($partner);

        if ($organizationId !== null && (int) $establishment->fda_organization_id !== $organizationId) {
            return 'That establishment belongs to a different organization.';
        }

        return self::glnAndCodeProblems($establishment->gln, $establishment->code);
    }

    public static function identityProblems(?string $gln, ?string $code): ?string
    {
        return self::glnAndCodeProblems($gln, $code);
    }

    private static function glnAndCodeProblems(?string $gln, ?string $code): ?string
    {
        if (filled($gln)) {
            $problem = null;
            $collect = function (string $message) use (&$problem): void {
                $problem ??= str_replace(':attribute', 'site GLN', $message);
            };

            (new ValidGln)->validate('gln', $gln, $collect);

            if ($problem !== null) {
                return $problem;
            }

            (new RejectTenantGln)->validate('gln', $gln, $collect);

            if ($problem !== null) {
                return $problem;
            }

            (new RejectPartnerGlnUnderOrgPrefix)->validate('gln', $gln, $collect);

            if ($problem !== null) {
                return $problem;
            }

            $glnOwner = Site::query()->where('gln', $gln)->first();

            if ($glnOwner !== null) {
                return 'GLN '.$gln.' already belongs to '.self::siteLabel($glnOwner).'. Two sites cannot share a GLN.';
            }
        }

        if (filled($code)) {
            $codeOwner = Site::query()->where('code', $code)->first();

            if ($codeOwner !== null) {
                return 'Site code '.$code.' already belongs to '.self::siteLabel($codeOwner).'.';
            }
        }

        return null;
    }

    private static function siteLabel(Site $site): string
    {
        $name = DisplayName::clean($site->name);

        return filled($name) ? 'site '.$name : 'another site';
    }
}
