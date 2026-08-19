<?php

namespace App\Filament\App\Resources\Products\Pages;

use App\Filament\App\Resources\Products\ProductResource;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;
}
