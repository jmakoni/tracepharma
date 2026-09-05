<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Admin;
use App\Models\CustomerOnboarding;
use App\Policies\Concerns\AuthorizesAdminActor;

class CustomerOnboardingPolicy
{
    use AuthorizesAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $this->adminManagesTenants($admin);
    }

    public function view(Admin $admin, CustomerOnboarding $onboarding): bool
    {
        return $this->viewAny($admin);
    }

    public function create(Admin $admin): bool
    {
        return false;
    }

    public function update(Admin $admin, CustomerOnboarding $onboarding): bool
    {
        return false;
    }

    public function delete(Admin $admin, CustomerOnboarding $onboarding): bool
    {
        return false;
    }
}
