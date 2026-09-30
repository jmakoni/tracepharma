<?php

namespace App\Filament\Admin\Resources\ConnectionRequests\Pages;

use App\Filament\Admin\Resources\ConnectionRequests\ConnectionRequestResource;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListConnectionRequests extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = ConnectionRequestResource::class;

    public function getSubheading(): ?string
    {
        return 'CLI equivalents: connections:list, connections:review, connections:suspend, connections:resume.';
    }
}
