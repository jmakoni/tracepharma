<?php

namespace App\Filament\Admin\Resources\Fda\FdaEstablishments\Pages;

use App\Filament\Admin\Resources\Fda\FdaEstablishments\FdaEstablishmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFdaEstablishments extends ListRecords
{
    protected static string $resource = FdaEstablishmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New establishment'),
        ];
    }
}
