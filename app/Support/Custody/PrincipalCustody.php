<?php

declare(strict_types=1);

namespace App\Support\Custody;

use App\Models\Epcis\Epc;
use App\Models\Principal;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Optional EPC-level principal ownership for Logistics3pl.
 * Soft principals (filters) stay available when enforcement is off.
 */
final class PrincipalCustody
{
    public static function forTenant(?Tenant $tenant = null): self
    {
        $tenant ??= tenant();

        return new self(
            TenantFeatures::forTenant($tenant),
            TenantSettings::forTenant($tenant),
        );
    }

    public function __construct(
        private readonly TenantFeatures $features,
        private readonly TenantSettings $settings,
    ) {}

    public function isEnforced(): bool
    {
        return $this->features->supportsPrincipals()
            && $this->settings->principalCustodyEnforced();
    }

    /**
     * @param  Epc|iterable<Epc|int>  $epcs
     *
     * @throws InvalidArgumentException
     */
    public function assertMatches(?int $activePrincipalId, Epc|iterable $epcs): void
    {
        if (! $this->isEnforced()) {
            return;
        }

        if ($activePrincipalId === null || $activePrincipalId <= 0) {
            throw new InvalidArgumentException(
                'Principal custody is enforced — select a principal before operating on serials.',
            );
        }

        $epcs = $this->normalize($epcs);

        foreach ($epcs as $epc) {
            $ownedBy = $epc->principal_id !== null ? (int) $epc->principal_id : null;

            if ($ownedBy === null) {
                throw new InvalidArgumentException(
                    'This serial belongs to another principal. (No principal ownership is recorded yet.)',
                );
            }

            if ($ownedBy !== $activePrincipalId) {
                throw new InvalidArgumentException(
                    'This serial belongs to another principal.',
                );
            }
        }
    }

    /**
     * Constrain an epcs query for read/export under enforcement.
     * Owners with all-site access see all principals; others are limited to
     * principals on their accessible sites (fail closed if none).
     *
     * @param  Builder<Model>|\Illuminate\Database\Query\Builder  $query
     * @return Builder<Model>|\Illuminate\Database\Query\Builder
     */
    public function constrainEpcQueryForActor($query, ?User $actor, string $column = 'epcs.principal_id')
    {
        if (! $this->isEnforced()) {
            return $query;
        }

        if ($actor instanceof User && $actor->can(Permissions::SitesAccessAll)) {
            return $query->whereNotNull($column);
        }

        if (! $actor instanceof User) {
            return $query->whereRaw('0 = 1');
        }

        $siteIds = SiteAccess::userSiteIds($actor)->all();
        if ($siteIds === []) {
            return $query->whereRaw('0 = 1');
        }

        $principalIds = Site::query()
            ->whereIn('id', $siteIds)
            ->whereNotNull('principal_id')
            ->pluck('principal_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($principalIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn($column, $principalIds);
    }

    /**
     * Active principal for floor pages without a session (site default).
     */
    public function activePrincipalIdForSite(?int $siteId): ?int
    {
        if (! $this->features->supportsPrincipals() || $siteId === null || $siteId <= 0) {
            return null;
        }

        $fromSite = Site::query()->whereKey($siteId)->value('principal_id');

        return $fromSite !== null ? (int) $fromSite : null;
    }

    /**
     * Resolve principal when opening receive/ship: explicit override, else site default.
     * When custody is enforced, fails closed if still unresolved.
     *
     * @param  'receive'|'ship'  $operation
     */
    public function resolveSessionPrincipalId(
        int $siteId,
        ?int $explicitPrincipalId = null,
        string $operation = 'receive',
    ): ?int {
        if (! $this->features->supportsPrincipals()) {
            return null;
        }

        $principalId = $explicitPrincipalId;
        if ($principalId === null || $principalId <= 0) {
            $principalId = $this->activePrincipalIdForSite($siteId);
        }

        if ($this->isEnforced() && ($principalId === null || $principalId <= 0)) {
            throw new DomainException(
                $operation === 'ship'
                    ? 'Principal custody is enforced — select a principal (or set the site default) before opening a ship order.'
                    : 'Principal custody is enforced — select a principal (or set the site default) before opening receive.',
            );
        }

        return $principalId !== null && $principalId > 0 ? $principalId : null;
    }

    /**
     * Fail closed for read/export when enforcement is on and the EPC is out of principal scope.
     */
    public function allowsRead(?int $activePrincipalId, Epc $epc): bool
    {
        if (! $this->isEnforced()) {
            return true;
        }

        try {
            $this->assertMatches($activePrincipalId, $epc);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Constrain an epcs query to the active principal when enforcement is on.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>|\Illuminate\Database\Query\Builder  $query
     * @return Builder<TModel>|\Illuminate\Database\Query\Builder
     */
    public function constrainEpcQuery($query, ?int $activePrincipalId, string $column = 'epcs.principal_id')
    {
        if (! $this->isEnforced()) {
            return $query;
        }

        if ($activePrincipalId === null || $activePrincipalId <= 0) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where($column, $activePrincipalId);
    }

    /**
     * @param  list<int>  $epcIds
     */
    public function stamp(array $epcIds, int $principalId): void
    {
        $epcIds = array_values(array_unique(array_filter(
            array_map('intval', $epcIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($epcIds === [] || $principalId <= 0) {
            return;
        }

        Epc::query()
            ->whereIn('id', $epcIds)
            ->update(['principal_id' => $principalId]);
    }

    /**
     * @param  Epc|iterable<Epc|int>  $epcs
     * @return list<Epc>
     */
    private function normalize(Epc|iterable $epcs): array
    {
        if ($epcs instanceof Epc) {
            return [$epcs];
        }

        $ids = [];
        $models = [];

        foreach ($epcs as $epc) {
            if ($epc instanceof Epc) {
                $models[] = $epc;
            } else {
                $ids[] = (int) $epc;
            }
        }

        if ($ids !== []) {
            $loaded = Epc::query()->whereIn('id', $ids)->get()->all();
            $models = array_merge($models, $loaded);
        }

        return $models;
    }
}
