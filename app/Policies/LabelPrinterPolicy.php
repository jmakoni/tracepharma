<?php

namespace App\Policies;

use App\Models\LabelPrinter;
use App\Models\User;
use App\Policies\Concerns\MaintainsIntegrations;

class LabelPrinterPolicy
{
    use MaintainsIntegrations;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LabelPrinter $printer): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isMaintainer($user);
    }

    public function update(User $user, LabelPrinter $printer): bool
    {
        return $this->isMaintainer($user);
    }

    public function delete(User $user, LabelPrinter $printer): bool
    {
        return $this->isDeleter($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isDeleter($user);
    }
}
