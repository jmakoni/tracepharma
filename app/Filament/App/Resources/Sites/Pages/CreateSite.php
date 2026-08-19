<?php

namespace App\Filament\App\Resources\Sites\Pages;

use App\Filament\App\Resources\Sites\SiteResource;
use App\Filament\Resources\Pages\CreateRecord;
use App\Models\Site;

class CreateSite extends CreateRecord
{
    protected static string $resource = SiteResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return Site::syncOrganizationFacilityFlag($data);
    }
}
