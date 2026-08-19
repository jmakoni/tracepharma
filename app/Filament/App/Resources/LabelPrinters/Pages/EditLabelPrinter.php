<?php

namespace App\Filament\App\Resources\LabelPrinters\Pages;

use App\Filament\App\Resources\LabelPrinters\LabelPrinterResource;
use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Support\RegulatoryCompliance;
use Filament\Actions\DeleteAction;

class EditLabelPrinter extends EditRecord
{
    protected static string $resource = LabelPrinterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RegulatoryCompliance::apply(
                DeleteAction::make(),
                'label_printers_delete',
                requireReason: true,
            ),
        ];
    }
}
