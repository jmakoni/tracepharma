<?php

namespace App\Filament\Admin\Resources\Fda\FdaWddFacilities\Pages;

use App\Filament\Admin\Resources\Fda\FdaWddFacilities\FdaWddFacilityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListFdaWddFacilities extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = FdaWddFacilityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New WDD facility'),
        ];
    }
}
