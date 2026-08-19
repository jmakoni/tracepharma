<?php

namespace App\Filament\App\Resources\ReadPoints\Pages;

use App\Filament\App\Resources\ReadPoints\ReadPointResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReadPoints extends ListRecords
{
    protected static string $resource = ReadPointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
