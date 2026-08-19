<?php

namespace App\Filament\App\Resources\ReadPoints\Pages;

use App\Filament\App\Resources\ReadPoints\ReadPointResource;
use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Support\RegulatoryCompliance;
use Filament\Actions\DeleteAction;

class EditReadPoint extends EditRecord
{
    protected static string $resource = ReadPointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RegulatoryCompliance::apply(
                DeleteAction::make(),
                'read_points_delete',
                requireReason: true,
            ),
        ];
    }
}
