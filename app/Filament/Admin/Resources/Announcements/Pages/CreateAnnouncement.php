<?php

namespace App\Filament\Admin\Resources\Announcements\Pages;

use App\Enums\AnnouncementStatus;
use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Filament\Resources\Pages\CreateRecord;
use App\Models\Admin;

class CreateAnnouncement extends CreateRecord
{
    protected static string $resource = AnnouncementResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $admin = auth('admin')->user();

        $data['status'] = AnnouncementStatus::Draft->value;
        $data['created_by_admin_id'] = $admin instanceof Admin ? $admin->getKey() : null;

        return $data;
    }
}
