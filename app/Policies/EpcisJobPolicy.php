<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EpcisJobKind;
use App\Models\EpcisJob;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;
use Illuminate\Database\Eloquent\Builder;

class EpcisJobPolicy
{
    public function viewAny(User $user): bool
    {
        $features = TenantFeatures::forTenant(tenant());

        return ($features->supportsInboundIntegrations()
            || $features->supportsOutboundIntegrations()
            || $features->supportsTransferring()
            || $features->supportsSsccLabeling())
            && JobRoleAccess::allows(Permissions::NavIntegrations, $user);
    }

    public function view(User $user, EpcisJob $job): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($user->can(Permissions::SitesAccessAll)) {
            return true;
        }

        $siteIds = SiteAccess::userSiteIds($user);

        return EpcisJob::query()
            ->whereKey($job->getKey())
            ->where(function (Builder $scoped) use ($siteIds): void {
                $scoped->whereIn('ship_from_site_id', $siteIds)
                    ->orWhere(function (Builder $inbound) use ($siteIds): void {
                        $inbound->where('kind', EpcisJobKind::InboundProcess->value)
                            ->whereHas(
                                'document',
                                fn (Builder $document): Builder => $document->whereIn('ship_to_site_id', $siteIds),
                            );
                    });
            })
            ->exists();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, EpcisJob $job): bool
    {
        return false;
    }

    public function delete(User $user, EpcisJob $job): bool
    {
        return false;
    }
}
