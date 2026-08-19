<?php

namespace App\Filament\App\Resources\Users\Pages;

use App\Filament\App\Resources\Users\Concerns\RestrictsOwnerRoleAssignment;
use App\Filament\App\Resources\Users\Concerns\SyncsUserSiteMembership;
use App\Filament\App\Resources\Users\UserResource;
use App\Filament\Resources\Pages\CreateRecord;
use App\Models\User;

class CreateUser extends CreateRecord
{
    use RestrictsOwnerRoleAssignment;
    use SyncsUserSiteMembership;

    protected static string $resource = UserResource::class;

    protected function beforeCreate(): void
    {
        $this->assertOwnerRoleAssignmentAllowed();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->extractSiteMembershipFromFormData($data);
    }

    protected function afterCreate(): void
    {
        /** @var User $record */
        $record = $this->getRecord();

        $this->syncSiteMembershipIfNeeded($record);
    }
}
