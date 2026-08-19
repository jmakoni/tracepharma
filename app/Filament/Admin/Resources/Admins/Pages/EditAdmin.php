<?php

namespace App\Filament\Admin\Resources\Admins\Pages;

use App\Enums\AdminRole;
use App\Filament\Admin\Resources\Admins\AdminResource;
use App\Filament\Resources\Pages\EditRecord;
use App\Models\Admin;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

class EditAdmin extends EditRecord
{
    protected static string $resource = AdminResource::class;

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

        if (! $record->hasRole(AdminRole::PlatformAdmin->value) && $this->platformAdminCount() === 0) {
            $record->assignRole(AdminRole::PlatformAdmin->value);

            Notification::make()
                ->title('Platform Admin role restored')
                ->body('At least one Platform Admin is required.')
                ->warning()
                ->send();
        }
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
