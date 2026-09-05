<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Roles\Pages;

use App\Filament\App\Resources\Roles\RoleResource;
use App\Filament\Notifications\Notification;
use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Support\Roles\RolePermissionEditor;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Role $record */
        $record = $this->getRecord();

        $data['display_name'] = RolePermissionEditor::roleLabel((string) $record->name, 'web');
        $data['permission_names'] = $record->permissions()->pluck('name')->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Role $record */
        $names = $data['permission_names'] ?? [];
        if (! is_array($names)) {
            $names = [];
        }

        RolePermissionEditor::sync($record, $names);

        return $record->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetToDefaults')
                ->label('Reset to defaults')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Reset role permissions')
                ->modalDescription('Restore this role’s capability bundle to the seeded catalog defaults.')
                ->action(function (): void {
                    /** @var Role $record */
                    $record = $this->getRecord();
                    RolePermissionEditor::resetToDefaults($record);
                    $this->fillForm();
                    Notification::make()
                        ->title('Role permissions reset')
                        ->success()
                        ->send();
                }),
        ];
    }
}
