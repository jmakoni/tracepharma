<?php

namespace App\Filament\App\Resources\InboundConnections\Pages;

use App\Filament\App\Resources\InboundConnections\InboundConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInboundConnections extends ListRecords
{
    protected static string $resource = InboundConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
