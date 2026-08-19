<?php

namespace App\Filament\App\Resources\FdaProducts\Pages;

use App\Filament\App\Resources\FdaProducts\FdaProductResource;
use Filament\Resources\Pages\ListRecords;

class ListFdaProducts extends ListRecords
{
    protected static string $resource = FdaProductResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
