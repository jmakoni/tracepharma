<?php

namespace App\Actions\Tenants;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;

class EnsureTenantOwner
{
    /**
     * @param  array{name: string, email: string, password: string}  $owner
     */
    public function handle(Tenant $tenant, array $owner): void
    {
        $tenant->run(function () use ($owner): void {
            $user = User::query()->where('email', $owner['email'])->first();

            if ($user === null) {
                $user = User::query()->create([
                    'name' => $owner['name'],
                    'email' => $owner['email'],
                    'password' => $owner['password'],
                ]);
            } else {
                $user->forceFill([
                    'name' => $owner['name'],
                    'password' => $owner['password'],
                ])->save();
            }

            $user->syncRoles([TenantRole::Owner->value]);
        });
    }
}
