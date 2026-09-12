<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Admin;
use App\Models\MailTemplate;
use App\Policies\Concerns\AuthorizesAdminActor;

class MailTemplatePolicy
{
    use AuthorizesAdminActor;

    public function viewAny(Admin $admin): bool
    {
        return $this->adminManagesAdmins($admin);
    }

    public function view(Admin $admin, MailTemplate $template): bool
    {
        return $this->viewAny($admin);
    }

    public function create(Admin $admin): bool
    {
        return false;
    }

    public function update(Admin $admin, MailTemplate $template): bool
    {
        return $this->viewAny($admin);
    }

    public function delete(Admin $admin, MailTemplate $template): bool
    {
        return false;
    }
}
