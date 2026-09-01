<?php

namespace App\Filament\App\Resources\Users\Pages;

use App\Enums\TenantRole;
use App\Filament\App\Resources\Users\Concerns\RestrictsOwnerRoleAssignment;
use App\Filament\App\Resources\Users\Concerns\RestrictsSupportEngineerAssignment;
use App\Filament\App\Resources\Users\Concerns\SyncsUserSiteMembership;
use App\Filament\App\Resources\Users\UserResource;
use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\User;
use Filament\Actions\DeleteAction;
use App\Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    use RestrictsOwnerRoleAssignment;
    use RestrictsSupportEngineerAssignment;
    use SyncsUserSiteMembership;

    protected static string $resource = UserResource::class;

    protected function beforeSave(): void
    {
        /** @var User $record */
        $record = $this->getRecord();

        $this->assertOwnerRoleAssignmentAllowed($record);
        $this->assertSupportEngineerAssignmentAllowed($record);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $record */
        $record = $this->getRecord();

        return array_merge($data, $this->siteMembershipFormDefaults($record));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var User $record */
        $record = $this->getRecord();

        $data = $this->preserveOwnerRoleForNonOwnerActor($data, $record);
        $data = $this->preserveSupportEngineerRoleForNonOwnerActor($data, $record);

        return $this->extractSiteMembershipFromFormData($data);
    }

    protected function getHeaderActions(): array
    {
        return [
            RegulatoryCompliance::apply(
                DeleteAction::make()
                    ->visible(fn (): bool => ! auth()->user()?->is($this->getRecord()))
                    ->before(function (DeleteAction $action): void {
                        if ($this->wouldRemoveLastOwner($this->getRecord())) {
                            Notification::make()
                                ->title('Cannot delete the last Owner')
                                ->danger()
                                ->send();
                            $action->cancel();
                        }
                    }),
                'users_delete',
                requireReason: true,
            ),
        ];
    }

    protected function afterSave(): void
    {
        /** @var User $record */
        $record = $this->getRecord();

        $this->syncSiteMembershipIfNeeded($record);

        if (! $record->hasRole(TenantRole::Owner->value) && $this->ownerCount() === 0) {
            $record->assignRole(TenantRole::Owner->value);

            Notification::make()
                ->title('Owner role restored')
                ->body('At least one Owner is required.')
                ->warning()
                ->send();
        }
    }

    private function wouldRemoveLastOwner(Model $record): bool
    {
        if (! $record instanceof User || ! $record->hasRole(TenantRole::Owner->value)) {
            return false;
        }

        return $this->ownerCount() <= 1;
    }

    private function ownerCount(): int
    {
        return User::role(TenantRole::Owner->value)->count();
    }
}
