<?php

namespace App\Filament\App\Resources\FdaProducts\Pages;

use App\Filament\App\Resources\FdaProducts\Actions\AddFdaProductPackagesAction;
use App\Filament\App\Resources\FdaProducts\FdaProductResource;
use Filament\Resources\Pages\ViewRecord;

class ViewFdaProduct extends ViewRecord
{
    protected static string $resource = FdaProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AddFdaProductPackagesAction::make(),
        ];
    }
}
