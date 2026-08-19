<?php

namespace App\Filament\App\Resources\Devices\Pages;

use App\Filament\App\Resources\Devices\DeviceResource;
use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Support\RegulatoryCompliance;
use Filament\Actions\DeleteAction;

class EditDevice extends EditRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RegulatoryCompliance::apply(
                DeleteAction::make(),
                'devices_delete',
                requireReason: true,
            ),
        ];
    }
}
