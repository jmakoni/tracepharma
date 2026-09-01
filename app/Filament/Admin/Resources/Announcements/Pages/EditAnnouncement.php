<?php

namespace App\Filament\Admin\Resources\Announcements\Pages;

use App\Filament\Admin\Resources\Announcements\Actions\AnnouncementHeaderActions;
use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;

class EditAnnouncement extends EditRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...AnnouncementHeaderActions::make(),
            DeleteAction::make(),
        ];
    }
}
