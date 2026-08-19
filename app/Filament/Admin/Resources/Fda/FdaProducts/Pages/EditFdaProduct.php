<?php

namespace App\Filament\Admin\Resources\Fda\FdaProducts\Pages;

use App\Filament\Admin\Resources\Fda\FdaProducts\FdaProductResource;
use Filament\Resources\Pages\EditRecord;

class EditFdaProduct extends EditRecord
{
    protected static string $resource = FdaProductResource::class;
}
