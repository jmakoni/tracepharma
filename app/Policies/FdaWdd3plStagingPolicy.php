<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Admin;
use App\Models\Fda\FdaWdd3plStaging;
use App\Policies\Concerns\AuthorizesFdaRegistryAdmin;

class FdaWdd3plStagingPolicy
{
    use AuthorizesFdaRegistryAdmin;

    public function view(Admin $admin, FdaWdd3plStaging $row): bool
    {
        return $this->viewAny($admin);
    }

    public function update(Admin $admin, FdaWdd3plStaging $row): bool
    {
        return false;
    }
}
