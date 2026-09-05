<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Admin;
use App\Models\Fda\FdaImportRun;
use App\Policies\Concerns\AuthorizesFdaRegistryAdmin;

class FdaImportRunPolicy
{
    use AuthorizesFdaRegistryAdmin;

    public function view(Admin $admin, FdaImportRun $run): bool
    {
        return $this->viewAny($admin);
    }

    public function update(Admin $admin, FdaImportRun $run): bool
    {
        return false;
    }
}
