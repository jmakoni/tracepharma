<?php

namespace App\Filament\App\Resources\OutboundConnections\Pages;

use App\Filament\App\Resources\OutboundConnections\OutboundConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOutboundConnections extends ListRecords
{
    protected static string $resource = OutboundConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
