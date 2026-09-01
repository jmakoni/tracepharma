<?php

namespace App\Filament\App\Resources\Users\Concerns;

use App\Enums\TenantRole;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\SupportEngineerEmail;
use App\Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

trait RestrictsSupportEngineerAssignment
{
    protected function actorCanAssignSupportEngineerRole(): bool
    {
        return JobRoleAccess::isOwner(auth()->user());
    }

    /**
     * Relationship CheckboxList roles are dehydrated(false), so getState() omits them.
     *
     * @return array<string, mixed>
     */
    protected function supportEngineerFormRawState(): array
    {
        return (array) ($this->form->getRawState() ?? []);
    }

    /**
     * @param  array<string, mixed>|null  $formState
     */
    protected function selectedRoleIdsIncludeSupportEngineer(?array $formState = null): bool
    {
        $supportRole = Role::query()
            ->where('guard_name', 'web')
            ->where('name', TenantRole::SupportEngineer->value)
            ->first(['id', 'name']);

        if ($supportRole === null) {
            return false;
        }

        $state = $formState ?? $this->supportEngineerFormRawState();
        $selected = (array) ($state['roles'] ?? []);

        foreach ($selected as $role) {
            if (is_numeric($role) && (int) $role === (int) $supportRole->getKey()) {
                return true;
            }

            if (is_string($role) && $role === TenantRole::SupportEngineer->value) {
                return true;
            }

            if (is_object($role) && method_exists($role, 'getKey') && (int) $role->getKey() === (int) $supportRole->getKey()) {
                return true;
            }
        }

        return false;
    }

    protected function assertSupportEngineerAssignmentAllowed(?User $record = null): void
    {
        $state = $this->supportEngineerFormRawState();
        $wantsSupport = $this->selectedRoleIdsIncludeSupportEngineer($state);
        $hadSupport = $record instanceof User && $record->hasRole(TenantRole::SupportEngineer->value);

        if (! $wantsSupport && ! $hadSupport) {
            return;
        }

        if ($wantsSupport && ! $this->actorCanAssignSupportEngineerRole() && ! $hadSupport) {
            Notification::make()
                ->title('Cannot assign Support Engineer')
                ->body('Only Owners can grant the Support Engineer role.')
                ->danger()
                ->send();

            $this->halt();
        }

        if ($hadSupport && ! $wantsSupport && ! $this->actorCanAssignSupportEngineerRole()) {
            Notification::make()
                ->title('Cannot remove Support Engineer')
                ->body('Only Owners can remove the Support Engineer role.')
                ->danger()
                ->send();

            $this->halt();
        }

        if (! $wantsSupport) {
            return;
        }

        $email = (string) ($state['email'] ?? $record?->email ?? '');

        if (! SupportEngineerEmail::isAllowed($email)) {
            throw ValidationException::withMessages([
                'data.email' => ['Support Engineer accounts must use a @tracepharma.io email.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function randomizePasswordForSupportEngineerCreate(array $data): array
    {
        if (! $this->selectedRoleIdsIncludeSupportEngineer($data)) {
            return $data;
        }

        $data['password'] = Str::password(40);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preserveSupportEngineerRoleForNonOwnerActor(array $data, User $record): array
    {
        if ($this->actorCanAssignSupportEngineerRole() || ! $record->hasRole(TenantRole::SupportEngineer->value)) {
            return $data;
        }

        $supportRoleId = Role::query()
            ->where('guard_name', 'web')
            ->where('name', TenantRole::SupportEngineer->value)
            ->value('id');

        if ($supportRoleId === null) {
            return $data;
        }

        $roles = array_map(intval(...), (array) ($data['roles'] ?? []));

        if (! in_array((int) $supportRoleId, $roles, true)) {
            $roles[] = (int) $supportRoleId;
            $data['roles'] = $roles;
        }

        return $data;
    }
}
