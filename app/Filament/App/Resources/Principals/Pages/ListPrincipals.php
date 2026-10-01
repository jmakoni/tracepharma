<?php

namespace App\Filament\App\Resources\Principals\Pages;

use App\Filament\App\Resources\Principals\PrincipalResource;
use App\Support\ShowsPrincipalsHonestyBanner;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListPrincipals extends ListRecords
{
    use HasColumnFilters;
    use ShowsPrincipalsHonestyBanner;

    protected static string $resource = PrincipalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
