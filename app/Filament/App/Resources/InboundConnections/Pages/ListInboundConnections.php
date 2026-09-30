<?php

namespace App\Filament\App\Resources\InboundConnections\Pages;

use App\Filament\App\Resources\InboundConnections\InboundConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListInboundConnections extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = InboundConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
