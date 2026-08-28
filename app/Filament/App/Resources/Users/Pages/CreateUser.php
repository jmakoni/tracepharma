<?php

namespace App\Filament\App\Resources\Users\Pages;

use App\Actions\Users\NotifyTenantUserAccountCreated;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Users\Concerns\RestrictsOwnerRoleAssignment;
use App\Filament\App\Resources\Users\Concerns\RestrictsSupportEngineerAssignment;
use App\Filament\App\Resources\Users\Concerns\SyncsUserSiteMembership;
use App\Filament\App\Resources\Users\UserResource;
use App\Filament\Resources\Pages\CreateRecord;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    use RestrictsOwnerRoleAssignment;
    use RestrictsSupportEngineerAssignment;
    use SyncsUserSiteMembership;

    protected static string $resource = UserResource::class;

    private bool $creatingSupportEngineer = false;

    protected function beforeCreate(): void
    {
        $this->assertOwnerRoleAssignmentAllowed();
        $this->assertSupportEngineerAssignmentAllowed();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Filament calls mutate before beforeCreate; roles live in raw state (dehydrated false).
        $this->creatingSupportEngineer = $this->selectedRoleIdsIncludeSupportEngineer();

        $data = $this->extractSiteMembershipFromFormData($data);

        if ($this->creatingSupportEngineer) {
            $data['password'] = Str::password(40);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var User $record */
        $record = $this->getRecord();

        $this->syncSiteMembershipIfNeeded($record);

        app(NotifyTenantUserAccountCreated::class)->handle($record);

        if ($this->creatingSupportEngineer || $record->hasRole(TenantRole::SupportEngineer->value)) {
            Notification::make()
                ->title('Support Engineer account created')
                ->body('A sign-in email was sent. The form password was not used — the mailbox owner must use Forgot password on the sign-in page to set their own password.')
                ->warning()
                ->send();
        }
    }
}
