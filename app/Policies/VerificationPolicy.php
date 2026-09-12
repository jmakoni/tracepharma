<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Verification;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;

class VerificationPolicy
{
    public function viewAny(User $user): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsVrs()
            && JobRoleAccess::allows(Permissions::NavVerify, $user);
    }

    public function view(User $user, Verification $verification): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return SiteAccess::constrainVerifications(
            Verification::query()->whereKey($verification->getKey()),
            'exception',
            'verified_by',
            $user,
        )->exists();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Verification $verification): bool
    {
        return false;
    }

    public function delete(User $user, Verification $verification): bool
    {
        return false;
    }
}
