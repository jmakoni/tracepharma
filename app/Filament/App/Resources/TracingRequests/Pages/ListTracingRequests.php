<?php

namespace App\Filament\App\Resources\TracingRequests\Pages;

use App\Filament\App\Resources\TracingRequests\TracingRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListTracingRequests extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = TracingRequestResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Respond to DSCSA tracing with operational evidence — not a live Pulse investigation network.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
