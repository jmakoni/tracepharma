<?php

namespace App\Filament\App\Resources\LocationDevices\Pages;

use App\Filament\App\Resources\LocationDevices\LocationDeviceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListLocationDevices extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = LocationDeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
