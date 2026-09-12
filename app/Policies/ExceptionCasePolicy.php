<?php

namespace App\Policies;

use App\Models\Exceptions\ExceptionCase;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;

class ExceptionCasePolicy
{
    public function viewAny(User $user): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsInboundIntegrations()
            && JobRoleAccess::allows(Permissions::NavExceptions, $user);
    }

    public function view(User $user, ExceptionCase $case): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return SiteAccess::constrainExceptionCases(
            ExceptionCase::query()->whereKey($case->getKey()),
            $user,
        )->exists();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ExceptionCase $case): bool
    {
        return false;
    }

    public function delete(User $user, ExceptionCase $case): bool
    {
        return false;
    }
}
