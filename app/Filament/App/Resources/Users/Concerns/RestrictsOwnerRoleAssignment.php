<?php

namespace App\Filament\App\Resources\Users\Concerns;

use App\Enums\TenantRole;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Filament\Notifications\Notification;
use Spatie\Permission\Models\Role;

trait RestrictsOwnerRoleAssignment
{
    protected function actorCanAssignOwnerRole(): bool
    {
        return JobRoleAccess::isOwner(auth()->user());
    }

    protected function assertOwnerRoleAssignmentAllowed(?User $record = null): void
    {
        if ($this->actorCanAssignOwnerRole()) {
            return;
        }

        $ownerRoleId = Role::query()
            ->where('guard_name', 'web')
            ->where('name', TenantRole::Owner->value)
            ->value('id');

        if ($ownerRoleId === null) {
            return;
        }

        // Relationship CheckboxList roles are dehydrated(false); getState() omits them.
        $selectedRoleIds = array_map(intval(...), (array) ($this->form->getRawState()['roles'] ?? []));

        if (in_array((int) $ownerRoleId, $selectedRoleIds, true) && ($record === null || ! $record->hasRole(TenantRole::Owner->value))) {
            Notification::make()
                ->title('Cannot assign Owner role')
                ->body('Only existing Owners can grant or change the Owner role.')
                ->danger()
                ->send();

            $this->halt();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preserveOwnerRoleForNonOwnerActor(array $data, User $record): array
    {
        if ($this->actorCanAssignOwnerRole() || ! $record->hasRole(TenantRole::Owner->value)) {
            return $data;
        }

        $ownerRoleId = Role::query()
            ->where('guard_name', 'web')
            ->where('name', TenantRole::Owner->value)
            ->value('id');

        if ($ownerRoleId === null) {
            return $data;
        }

        $roles = array_map(intval(...), (array) ($data['roles'] ?? []));

        if (! in_array((int) $ownerRoleId, $roles, true)) {
            $roles[] = (int) $ownerRoleId;
            $data['roles'] = $roles;
        }

        return $data;
    }
}
