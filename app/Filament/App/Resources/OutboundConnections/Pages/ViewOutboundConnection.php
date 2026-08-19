<?php

namespace App\Filament\App\Resources\OutboundConnections\Pages;

use App\Filament\App\Resources\OutboundConnections\OutboundConnectionResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOutboundConnection extends ViewRecord
{
    protected static string $resource = OutboundConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
