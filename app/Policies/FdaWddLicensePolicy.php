<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Admin;
use App\Models\Fda\FdaWddLicense;
use App\Policies\Concerns\AuthorizesFdaRegistryAdmin;

class FdaWddLicensePolicy
{
    use AuthorizesFdaRegistryAdmin;

    public function view(Admin $admin, FdaWddLicense $license): bool
    {
        return $this->viewAny($admin);
    }

    public function update(Admin $admin, FdaWddLicense $license): bool
    {
        return false;
    }
}
