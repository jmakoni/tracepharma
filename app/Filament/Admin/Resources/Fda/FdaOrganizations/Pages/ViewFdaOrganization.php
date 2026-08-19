<?php

namespace App\Filament\Admin\Resources\Fda\FdaOrganizations\Pages;

use App\Filament\Admin\Resources\Fda\FdaOrganizations\FdaOrganizationResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFdaOrganization extends ViewRecord
{
    protected static string $resource = FdaOrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
