<?php

namespace App\Filament\App\Resources\Products\Pages;

use App\Filament\App\Resources\Products\ProductResource;
use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Support\RegulatoryCompliance;
use Filament\Actions\DeleteAction;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RegulatoryCompliance::apply(
                DeleteAction::make(),
                'products_delete',
                requireReason: true,
            ),
        ];
    }
}
