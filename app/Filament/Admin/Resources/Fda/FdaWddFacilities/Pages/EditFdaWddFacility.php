<?php

namespace App\Filament\Admin\Resources\Fda\FdaWddFacilities\Pages;

use App\Filament\Admin\Resources\Fda\FdaWddFacilities\FdaWddFacilityResource;
use App\Filament\Admin\Support\SyncFdaFacilityAddressFingerprint;
use Filament\Resources\Pages\EditRecord;

class EditFdaWddFacility extends EditRecord
{
    protected static string $resource = FdaWddFacilityResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return SyncFdaFacilityAddressFingerprint::apply($data);
    }
}
