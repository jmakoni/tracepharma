<?php

namespace App\Filament\Admin\Resources\Fda\FdaEstablishments\Pages;

use App\Filament\Admin\Resources\Fda\FdaEstablishments\FdaEstablishmentResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFdaEstablishment extends ViewRecord
{
    protected static string $resource = FdaEstablishmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
