<?php

namespace App\Policies;

use App\Models\Principal;
use App\Models\User;
use App\Policies\Concerns\MaintainsMasterData;

class PrincipalPolicy
{
    use MaintainsMasterData;

    public function viewAny(User $user): bool
    {
        return $this->isMaintainer($user);
    }

    public function view(User $user, Principal $principal): bool
    {
        return $this->isMaintainer($user);
    }

    public function create(User $user): bool
    {
        return $this->isMaintainer($user);
    }

    public function update(User $user, Principal $principal): bool
    {
        return $this->isMaintainer($user);
    }

    public function updateAny(User $user): bool
    {
        return $this->isMaintainer($user);
    }

    public function delete(User $user, Principal $principal): bool
    {
        return $this->isDeleter($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isDeleter($user);
    }
}
