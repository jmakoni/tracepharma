<?php

namespace App\Filament\Admin\Resources\Fda\FdaEstablishments\Pages;

use App\Filament\Admin\Resources\Fda\FdaEstablishments\FdaEstablishmentResource;
use App\Filament\Admin\Support\SyncFdaFacilityAddressFingerprint;
use Filament\Resources\Pages\EditRecord;

class EditFdaEstablishment extends EditRecord
{
    protected static string $resource = FdaEstablishmentResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return SyncFdaFacilityAddressFingerprint::apply($data);
    }
}
