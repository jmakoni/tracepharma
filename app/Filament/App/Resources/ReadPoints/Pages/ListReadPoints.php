<?php

namespace App\Filament\App\Resources\ReadPoints\Pages;

use App\Filament\App\Resources\ReadPoints\ReadPointResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListReadPoints extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = ReadPointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
