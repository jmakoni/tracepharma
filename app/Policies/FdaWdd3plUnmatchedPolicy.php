<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Admin;
use App\Models\Fda\FdaWdd3plUnmatched;
use App\Policies\Concerns\AuthorizesFdaRegistryAdmin;

class FdaWdd3plUnmatchedPolicy
{
    use AuthorizesFdaRegistryAdmin;

    public function view(Admin $admin, FdaWdd3plUnmatched $row): bool
    {
        return $this->viewAny($admin);
    }

    public function update(Admin $admin, FdaWdd3plUnmatched $row): bool
    {
        return false;
    }
}
