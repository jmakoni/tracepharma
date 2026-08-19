<?php

namespace App\Filament\Admin\Resources\Fda\FdaWddFacilities\Pages;

use App\Filament\Admin\Resources\Fda\FdaWddFacilities\FdaWddFacilityResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFdaWddFacility extends ViewRecord
{
    protected static string $resource = FdaWddFacilityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
