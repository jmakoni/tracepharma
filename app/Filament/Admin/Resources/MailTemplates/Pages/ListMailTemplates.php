<?php

namespace App\Filament\Admin\Resources\MailTemplates\Pages;

use App\Filament\Admin\Resources\MailTemplates\MailTemplateResource;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListMailTemplates extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = MailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
