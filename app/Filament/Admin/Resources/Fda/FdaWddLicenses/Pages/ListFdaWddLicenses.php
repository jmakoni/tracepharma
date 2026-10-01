<?php

namespace App\Filament\Admin\Resources\Fda\FdaWddLicenses\Pages;

use App\Filament\Admin\Resources\Fda\FdaWddLicenses\FdaWddLicenseResource;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListFdaWddLicenses extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = FdaWddLicenseResource::class;
}
