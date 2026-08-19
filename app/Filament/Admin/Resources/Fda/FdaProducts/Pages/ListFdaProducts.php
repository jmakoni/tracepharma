<?php

namespace App\Filament\Admin\Resources\Fda\FdaProducts\Pages;

use App\Filament\Admin\Resources\Fda\FdaProducts\FdaProductResource;
use Filament\Resources\Pages\ListRecords;

class ListFdaProducts extends ListRecords
{
    protected static string $resource = FdaProductResource::class;
}
