<?php

namespace App\Filament\Admin\Resources\Fda\FdaWddFacilities\Pages;

use App\Filament\Admin\Resources\Fda\FdaWddFacilities\FdaWddFacilityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFdaWddFacilities extends ListRecords
{
    protected static string $resource = FdaWddFacilityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New WDD facility'),
        ];
    }
}
