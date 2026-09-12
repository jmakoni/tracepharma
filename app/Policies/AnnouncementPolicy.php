<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AnnouncementStatus;
use App\Models\Admin;
use App\Models\Announcement;
use App\Policies\Concerns\AuthorizesAdminActor;

class AnnouncementPolicy
{
    use AuthorizesAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $this->adminManagesAdmins($admin);
    }

    public function view(Admin $admin, Announcement $announcement): bool
    {
        return $this->viewAny($admin);
    }

    public function create(Admin $admin): bool
    {
        return $this->viewAny($admin);
    }

    public function update(Admin $admin, Announcement $announcement): bool
    {
        return $this->viewAny($admin);
    }

    public function delete(Admin $admin, Announcement $announcement): bool
    {
        return $this->viewAny($admin)
            && $announcement->status === AnnouncementStatus::Draft;
    }
}
