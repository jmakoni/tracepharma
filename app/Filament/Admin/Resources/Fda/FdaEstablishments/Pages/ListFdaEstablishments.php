<?php

namespace App\Filament\Admin\Resources\Fda\FdaEstablishments\Pages;

use App\Filament\Admin\Resources\Fda\FdaEstablishments\FdaEstablishmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListFdaEstablishments extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = FdaEstablishmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New establishment'),
        ];
    }
}
