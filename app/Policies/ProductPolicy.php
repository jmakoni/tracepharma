<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Policies\Concerns\MaintainsMasterData;

class ProductPolicy
{
    use MaintainsMasterData;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Product $product): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isMaintainer($user);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->isMaintainer($user);
    }

    public function updateAny(User $user): bool
    {
        return $this->isMaintainer($user);
    }

    /**
     * Editing a partner's assortment leaves the product record intact, so it is a
     * maintenance action rather than a delete.
     */
    public function attach(User $user, Product $product): bool
    {
        return $this->isMaintainer($user);
    }

    public function detach(User $user, Product $product): bool
    {
        return $this->isMaintainer($user);
    }

    public function detachAny(User $user): bool
    {
        return $this->isMaintainer($user);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->isDeleter($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isDeleter($user);
    }
}
