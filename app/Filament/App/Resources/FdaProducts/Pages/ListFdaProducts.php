<?php

namespace App\Filament\App\Resources\FdaProducts\Pages;

use App\Filament\App\Resources\FdaProducts\FdaProductResource;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListFdaProducts extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = FdaProductResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
