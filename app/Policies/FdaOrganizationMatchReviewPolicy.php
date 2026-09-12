<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Admin;
use App\Models\Fda\FdaOrganizationMatchReview;
use App\Policies\Concerns\AuthorizesFdaRegistryAdmin;

class FdaOrganizationMatchReviewPolicy
{
    use AuthorizesFdaRegistryAdmin;

    public function view(Admin $admin, FdaOrganizationMatchReview $review): bool
    {
        return $this->viewAny($admin);
    }

    public function update(Admin $admin, FdaOrganizationMatchReview $review): bool
    {
        return false;
    }
}
