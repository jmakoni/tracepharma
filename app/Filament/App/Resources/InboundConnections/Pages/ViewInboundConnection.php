<?php

namespace App\Filament\App\Resources\InboundConnections\Pages;

use App\Filament\App\Resources\InboundConnections\InboundConnectionResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInboundConnection extends ViewRecord
{
    protected static string $resource = InboundConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
