<?php

namespace App\Filament\App\Resources\LabelPrinters\Pages;

use App\Filament\App\Resources\LabelPrinters\LabelPrinterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListLabelPrinters extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = LabelPrinterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
