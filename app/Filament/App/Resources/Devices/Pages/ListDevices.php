<?php

namespace App\Filament\App\Resources\Devices\Pages;

use App\Filament\App\Resources\Devices\DeviceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListDevices extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
