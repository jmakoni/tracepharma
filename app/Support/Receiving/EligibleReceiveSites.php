<?php

namespace App\Support\Receiving;

use App\Models\Epcis\EpcisDocument;
use App\Models\Site;
use App\Models\User;
use App\Support\Auth\SiteAccess;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * True organization facilities (active, with a GLN).
 *
 * Shared options for receive site, intracompany transfer from/to, and org
 * default receive / ship-from dropdowns. Requires is_organization_facility
 * and trading_partner_id IS NULL (see Site::scopeOwnedByOrganization).
 */
final class EligibleReceiveSites
{
    /**
     * Whether a site matches tenant-wide receive eligibility ({@see forOrganization()}).
     *
     * Pure check for UI badges/filters; does not apply user site-access scoping.
     */
    public static function isEligible(Site $site): bool
    {
        if (! (bool) $site->is_organization_facility || $site->trading_partner_id !== null) {
            return false;
        }

        if (! (bool) $site->is_active || blank($site->gln)) {
            return false;
        }

        $code = $site->code;

        return ! (is_string($code) && str_starts_with($code, 'TEST-'));
    }

    /**
     * All organization facilities with GLN (tenant-wide; settings / system use).
     *
     * @return Builder<Site>
     */
    public static function forOrganization(): Builder
    {
        return self::excludeTestSiteCodes(
            Site::query()
                ->ownedByOrganization()
                ->where('is_active', true)
                ->whereNotNull('gln')
                ->where('gln', '!=', ''),
        )
            ->orderByDesc('is_headquarters')
            ->orderBy('name');
    }

    /**
     * Eligible receive sites visible to the given user (or authenticated user).
     *
     * @return Builder<Site>
     */
    public static function query(?User $user = null): Builder
    {
        $user ??= auth()->user();

        return self::excludeTestSiteCodes(
            SiteAccess::eligibleOrganizationSitesQuery($user)
                ->where('is_active', true)
                ->whereNotNull('gln')
                ->where('gln', '!=', ''),
        )
            ->orderByDesc('is_headquarters')
            ->orderBy('name');
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    private static function excludeTestSiteCodes(Builder $query): Builder
    {
        return $query->where(function (Builder $inner): void {
            $inner->whereNull('code')
                ->orWhere('code', 'not like', 'TEST-%');
        });
    }

    public static function count(?User $user = null): int
    {
        return (int) self::query($user)->count();
    }

    public static function requiresChoice(?User $user = null): bool
    {
        return self::count($user) > 1;
    }

    /**
     * @return array<int, string>
     */
    public static function options(?User $user = null): array
    {
        return self::query($user)
            ->get(['id', 'name', 'gln'])
            ->mapWithKeys(fn (Site $site): array => [
                (int) $site->getKey() => $site->name.' ('.$site->gln.')',
            ])
            ->all();
    }

    /**
     * Org-wide eligible site options (no user site-pivot filter).
     *
     * Use for transfer from/to, SSCC commission site, and similar org-admin
     * workflows. Receive floor selects should keep {@see options()} / {@see query()}.
     *
     * @return array<int, string>
     */
    public static function organizationOptions(): array
    {
        return self::forOrganization()
            ->get(['id', 'name', 'gln'])
            ->mapWithKeys(fn (Site $site): array => [
                (int) $site->getKey() => $site->name.' ('.$site->gln.')',
            ])
            ->all();
    }

    /**
     * Prefer ResolveReceivingSite (ship-to → default → HQ/first) for the select default.
     */
    public static function defaultSiteId(EpcisDocument $document): ?int
    {
        $user = auth()->user();
        $user = $user instanceof User ? $user : null;

        try {
            $resolved = app(ResolveReceivingSite::class)->handle($document);
        } catch (DomainException) {
            $resolved = null;
        }

        if ($resolved !== null && ($user === null || SiteAccess::canAccessSite($user, $resolved))) {
            return $resolved;
        }

        if ($user === null) {
            return null;
        }

        $defaultSiteId = SiteAccess::defaultSiteId($user);

        if ($defaultSiteId !== null && self::query($user)->whereKey($defaultSiteId)->exists()) {
            return $defaultSiteId;
        }

        $first = self::query($user)->first();

        return $first !== null ? (int) $first->getKey() : null;
    }
}
