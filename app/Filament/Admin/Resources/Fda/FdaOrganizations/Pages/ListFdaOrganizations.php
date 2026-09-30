<?php

namespace App\Filament\Admin\Resources\Fda\FdaOrganizations\Pages;

use App\Filament\Admin\Resources\Fda\FdaOrganizations\FdaOrganizationResource;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListFdaOrganizations extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = FdaOrganizationResource::class;
}
