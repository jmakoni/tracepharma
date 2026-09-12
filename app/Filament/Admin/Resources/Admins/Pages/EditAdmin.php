<?php

namespace App\Filament\Admin\Resources\Admins\Pages;

use App\Enums\AdminRole;
use App\Filament\Admin\Resources\Admins\AdminResource;
use App\Filament\Notifications\Notification;
use App\Filament\Resources\Pages\EditRecord;
use App\Models\Admin;
use Filament\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Model;

class EditAdmin extends EditRecord
{
    protected static string $resource = AdminResource::class;

    /** @var array{is_active?: bool, must_change_password?: bool} */
    private array $accountSecurityAttributes = [];

    protected function beforeSave(): void
    {
        /** @var Admin $record */
        $record = $this->getRecord();

        if (auth('admin')->user()?->is($record) && array_key_exists('is_active', $this->data) && ! $this->data['is_active']) {
            $this->data['is_active'] = true;
            Notification::make()
                ->title('Cannot disable your own account')
                ->danger()
                ->send();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->extractAccountSecurityFromFormData($data);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => ! auth('admin')->user()?->is($this->getRecord()))
                ->before(function (DeleteAction $action): void {
                    if ($this->wouldRemoveLastPlatformAdmin($this->getRecord())) {
                        Notification::make()
                            ->title('Cannot delete the last Platform Admin')
                            ->danger()
                            ->send();
                        $action->cancel();
                    }
                }),
        ];
    }

    protected function afterSave(): void
    {
        /** @var Admin $record */
        $record = $this->getRecord();

        if ($this->accountSecurityAttributes !== []) {
            $record->forceFill($this->accountSecurityAttributes)->save();
        }

        if (! $record->hasRole(AdminRole::PlatformAdmin->value) && $this->platformAdminCount() === 0) {
            $record->assignRole(AdminRole::PlatformAdmin->value);

            Notification::make()
                ->title('Platform Admin role restored')
                ->body('At least one Platform Admin is required.')
                ->warning()
                ->send();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function extractAccountSecurityFromFormData(array $data): array
    {
        $this->accountSecurityAttributes = [];

        foreach (['is_active', 'must_change_password'] as $key) {
            if (array_key_exists($key, $data)) {
                $this->accountSecurityAttributes[$key] = (bool) $data[$key];
                unset($data[$key]);
            }
        }

        return $data;
    }

    private function wouldRemoveLastPlatformAdmin(Model $record): bool
    {
        if (! $record instanceof Admin || ! $record->hasRole(AdminRole::PlatformAdmin->value)) {
            return false;
        }

        return $this->platformAdminCount() <= 1;
    }

    private function platformAdminCount(): int
    {
        return Admin::role(AdminRole::PlatformAdmin->value)->count();
    }
}
