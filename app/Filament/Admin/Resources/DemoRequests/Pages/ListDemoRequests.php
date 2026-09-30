<?php

namespace App\Filament\Admin\Resources\DemoRequests\Pages;

use App\Filament\Admin\Resources\DemoRequests\DemoRequestResource;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListDemoRequests extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = DemoRequestResource::class;
}
