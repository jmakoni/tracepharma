<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Fda3911Report;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;

class Fda3911ReportPolicy
{
    public function viewAny(User $user): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsComplianceCases()
            && JobRoleAccess::allows(Permissions::NavCompliance, $user);
    }

    public function view(User $user, Fda3911Report $report): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return SiteAccess::constrainExceptionCaseRelation(
            Fda3911Report::query()->whereKey($report->getKey()),
            'exceptionCase',
            $user,
        )->exists();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Fda3911Report $report): bool
    {
        return $this->view($user, $report);
    }

    public function delete(User $user, Fda3911Report $report): bool
    {
        return false;
    }
}
