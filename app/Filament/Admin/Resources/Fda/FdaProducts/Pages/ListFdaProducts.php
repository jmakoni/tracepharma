<?php

namespace App\Filament\Admin\Resources\Fda\FdaProducts\Pages;

use App\Filament\Admin\Resources\Fda\FdaProducts\FdaProductResource;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListFdaProducts extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = FdaProductResource::class;
}
