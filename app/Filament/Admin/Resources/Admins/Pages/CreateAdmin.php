<?php

namespace App\Filament\Admin\Resources\Admins\Pages;

use App\Filament\Admin\Resources\Admins\AdminResource;
use App\Filament\Resources\Pages\CreateRecord;
use App\Models\Admin;

class CreateAdmin extends CreateRecord
{
    protected static string $resource = AdminResource::class;

    /** @var array{is_active?: bool, must_change_password?: bool} */
    private array $accountSecurityAttributes = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->extractAccountSecurityFromFormData($data);
    }

    protected function afterCreate(): void
    {
        /** @var Admin $record */
        $record = $this->getRecord();

        if ($this->accountSecurityAttributes !== []) {
            $record->forceFill($this->accountSecurityAttributes)->save();
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
}
