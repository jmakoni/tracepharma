<?php

namespace App\Filament\App\Resources\TracingRequests\Pages;

use App\Filament\App\Resources\TracingRequests\TracingRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTracingRequests extends ListRecords
{
    protected static string $resource = TracingRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
