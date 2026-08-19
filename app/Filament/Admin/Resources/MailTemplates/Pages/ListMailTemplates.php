<?php

namespace App\Filament\Admin\Resources\MailTemplates\Pages;

use App\Filament\Admin\Resources\MailTemplates\MailTemplateResource;
use Filament\Resources\Pages\ListRecords;

class ListMailTemplates extends ListRecords
{
    protected static string $resource = MailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
