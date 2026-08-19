<?php

namespace App\Filament\App\Resources\LocationDevices\Pages;

use App\Filament\App\Resources\LocationDevices\LocationDeviceResource;
use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Support\RegulatoryCompliance;
use Filament\Actions\DeleteAction;

class EditLocationDevice extends EditRecord
{
    protected static string $resource = LocationDeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RegulatoryCompliance::apply(
                DeleteAction::make(),
                'location_devices_delete',
                requireReason: true,
            ),
        ];
    }
}
