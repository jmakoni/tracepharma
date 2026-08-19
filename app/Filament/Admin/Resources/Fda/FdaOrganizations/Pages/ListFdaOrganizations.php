<?php

namespace App\Filament\Admin\Resources\Fda\FdaOrganizations\Pages;

use App\Filament\Admin\Resources\Fda\FdaOrganizations\FdaOrganizationResource;
use Filament\Resources\Pages\ListRecords;

class ListFdaOrganizations extends ListRecords
{
    protected static string $resource = FdaOrganizationResource::class;
}
