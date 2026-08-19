<?php

namespace App\Filament\App\Resources\LocationDevices\Pages;

use App\Filament\App\Resources\LocationDevices\LocationDeviceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLocationDevices extends ListRecords
{
    protected static string $resource = LocationDeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
