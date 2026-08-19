<?php

namespace App\Support\Auth;

use App\Models\Site;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Site access is always limited to organization facilities, pivot rows included:
 * a site that has been handed to a trading partner stops granting access even
 * while its site_user rows survive.
 */
final class SiteAccess
{
    /**
     * @return Collection<int, int>
     */
    public static function userSiteIds(User $user): Collection
    {
        if ($user->can(Permissions::SitesAccessAll)) {
            return self::organizationFacilityQuery()
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id);
        }

        return $user->sites()
            ->ownedByOrganization()
            ->pluck('sites.id')
            ->map(fn (mixed $id): int => (int) $id);
    }

    public static function canAccessSite(User $user, int $siteId): bool
    {
        if ($user->can(Permissions::SitesAccessAll)) {
            return self::organizationFacilityQuery()
                ->whereKey($siteId)
                ->exists();
        }

        return $user->sites()
            ->ownedByOrganization()
            ->whereKey($siteId)
            ->exists();
    }

    /**
     * @param  list<int>  $siteIds
     */
    public static function canAccessSites(User $user, array $siteIds): bool
    {
        if ($siteIds === []) {
            return true;
        }

        foreach ($siteIds as $siteId) {
            if (! self::canAccessSite($user, (int) $siteId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return Builder<Site>
     */
    public static function eligibleOrganizationSitesQuery(?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (! $user instanceof User) {
            return Site::query()->whereRaw('0 = 1');
        }

        $query = self::organizationFacilityQuery();

        if ($user->can(Permissions::SitesAccessAll)) {
            return $query;
        }

        return $query->whereIn('id', self::userSiteIds($user));
    }

    public static function assertCanAccessSite(
        User $user,
        int $siteId,
        string $message = 'You do not have access to this site.',
    ): void {
        if (! self::canAccessSite($user, $siteId)) {
            throw new AuthorizationException($message);
        }
    }

    /**
     * Constrain an EpcisDocument query by the actor's accessible ship-to sites.
     *
     * Access-all users see organization facilities (and unmapped null ship-to).
     * Site-restricted users only see documents ship-to their assigned sites.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function constrainShipToSite(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('0 = 1');
        }

        $siteIds = self::userSiteIds($user);

        if ($user->can(Permissions::SitesAccessAll)) {
            return $query->where(function (Builder $outer) use ($siteIds): void {
                $outer->whereIn('ship_to_site_id', $siteIds)
                    ->orWhereNull('ship_to_site_id');
            });
        }

        return $query->whereIn('ship_to_site_id', $siteIds);
    }

    /**
     * Constrain models that reach site via a related inbound document.ship_to_site_id.
     *
     * Document-less rows (find-recall) are AccessAll-only — not shared across site-restricted users.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function constrainViaDocumentShipTo(
        Builder $query,
        string $relation = 'document',
        ?User $user = null,
    ): Builder {
        $user ??= auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('0 = 1');
        }

        $siteIds = self::userSiteIds($user);
        $accessAll = $user->can(Permissions::SitesAccessAll);

        return $query->where(function (Builder $outer) use ($relation, $siteIds, $accessAll): void {
            $outer->whereHas(
                $relation,
                function (Builder $document) use ($siteIds, $accessAll): void {
                    $document->where(function (Builder $shipTo) use ($siteIds, $accessAll): void {
                        $shipTo->whereIn('ship_to_site_id', $siteIds);
                        if ($accessAll) {
                            $shipTo->orWhereNull('ship_to_site_id');
                        }
                    });
                },
            );

            if ($accessAll) {
                $outer->orWhereDoesntHave($relation);
            }
        });
    }

    /**
     * Constrain exception cases by direct site_id or related document ship_to_site_id.
     *
     * Access-all users see every case (no site filter).
     * Site-restricted users see cases whose site_id or document.ship_to_site_id
     * is in their assigned sites. Null site_id / document-less cases are
     * AccessAll-only (fail-closed).
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function constrainExceptionCases(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('0 = 1');
        }

        if ($user->can(Permissions::SitesAccessAll)) {
            return $query;
        }

        $siteIds = self::userSiteIds($user);

        return $query->where(function (Builder $outer) use ($siteIds): void {
            $outer->whereIn('site_id', $siteIds)
                ->orWhereHas('document', function (Builder $document) use ($siteIds): void {
                    $document->whereIn('ship_to_site_id', $siteIds);
                });
        });
    }

    /**
     * Apply {@see constrainExceptionCases()} through a BelongsTo exception relation.
     *
     * Access-all users see every row (including unlinked). Restricted users only
     * see rows whose related exception is in scope (site_id OR document ship_to).
     * Unlinked / null-exception rows are fail-closed for restricted users.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function constrainExceptionCaseRelation(
        Builder $query,
        string $relation = 'exceptionCase',
        ?User $user = null,
    ): Builder {
        $user ??= auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('0 = 1');
        }

        if ($user->can(Permissions::SitesAccessAll)) {
            return $query;
        }

        return $query->whereHas(
            $relation,
            fn (Builder $exception): Builder => self::constrainExceptionCases($exception, $user),
        );
    }

    /**
     * Scope verifications for site-restricted users: actor-owned rows or exception-linked
     * cases at allowed sites. Access-all users see every row (unchanged).
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function constrainVerifications(
        Builder $query,
        string $exceptionRelation = 'exception',
        string $verifiedByColumn = 'verified_by',
        ?User $user = null,
    ): Builder {
        $user ??= auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('0 = 1');
        }

        if ($user->can(Permissions::SitesAccessAll)) {
            return $query;
        }

        return $query->where(function (Builder $outer) use ($exceptionRelation, $verifiedByColumn, $user): void {
            $outer->where($verifiedByColumn, $user->getKey())
                ->orWhereHas(
                    $exceptionRelation,
                    fn (Builder $exception): Builder => self::constrainExceptionCases($exception, $user),
                );
        });
    }

    /**
     * Scope inbound catalog documents the way {@see \App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource} does.
     *
     * Access-all users see every document. Restricted users only see ship-to
     * assigned sites (null ship-to is fail-closed).
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function constrainInboundDocuments(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('0 = 1');
        }

        if ($user->can(Permissions::SitesAccessAll)) {
            return $query;
        }

        return $query->whereIn('ship_to_site_id', self::userSiteIds($user));
    }

    /**
     * Scope EPCs that appear on at least one inbound document the user may see.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function constrainEpcsViaInboundDocuments(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('0 = 1');
        }

        if ($user->can(Permissions::SitesAccessAll)) {
            return $query;
        }

        $siteIds = self::userSiteIds($user);

        return $query->whereExists(function ($exists) use ($siteIds): void {
            $exists->selectRaw('1')
                ->from('document_epcs')
                ->join('epcis_documents', 'epcis_documents.id', '=', 'document_epcs.document_id')
                ->whereColumn('document_epcs.epc_id', 'epcs.id')
                ->whereIn('epcis_documents.ship_to_site_id', $siteIds);
        });
    }

    /**
     * Organization-facility site id for a GLN, or null when the GLN is unknown / partner-owned.
     */
    public static function organizationSiteIdForGln(?string $gln): ?int
    {
        if ($gln === null || $gln === '') {
            return null;
        }

        $siteId = self::organizationFacilityQuery()
            ->where('gln', $gln)
            ->value('id');

        return $siteId !== null ? (int) $siteId : null;
    }

    public static function canAccessShipToSite(?User $user, ?int $shipToSiteId): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if ($shipToSiteId === null) {
            return $user->can(Permissions::SitesAccessAll);
        }

        return self::canAccessSite($user, $shipToSiteId);
    }

    public static function canAccessShipFromSite(?User $user, ?int $shipFromSiteId): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if ($user->can(Permissions::SitesAccessAll)) {
            return true;
        }

        if ($shipFromSiteId === null) {
            return false;
        }

        return self::canAccessSite($user, $shipFromSiteId);
    }

    public static function defaultSiteId(User $user): ?int
    {
        if ($user->can(Permissions::SitesAccessAll)) {
            $siteId = self::organizationFacilityQuery()
                ->orderByDesc('is_headquarters')
                ->orderBy('id')
                ->value('id');

            return $siteId !== null ? (int) $siteId : null;
        }

        $defaultSiteId = $user->sites()
            ->ownedByOrganization()
            ->wherePivot('is_default', true)
            ->value('sites.id');

        if ($defaultSiteId !== null) {
            return (int) $defaultSiteId;
        }

        $siteIds = self::userSiteIds($user);

        if ($siteIds->count() === 1) {
            return (int) $siteIds->first();
        }

        $siteId = $user->sites()
            ->ownedByOrganization()
            ->orderByDesc('sites.is_headquarters')
            ->orderBy('sites.id')
            ->value('sites.id');

        return $siteId !== null ? (int) $siteId : null;
    }

    /**
     * @return Builder<Site>
     */
    public static function organizationFacilityQuery(): Builder
    {
        return Site::query()->ownedByOrganization();
    }
}
