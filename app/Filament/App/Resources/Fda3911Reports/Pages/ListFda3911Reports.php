<?php

namespace App\Filament\App\Resources\Fda3911Reports\Pages;

use App\Filament\App\Resources\Fda3911Reports\Fda3911ReportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFda3911Reports extends ListRecords
{
    protected static string $resource = Fda3911ReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
