<?php

namespace App\Filament\App\Resources\EpcisJobs\Pages;

use App\Filament\App\Resources\EpcisJobs\EpcisJobResource;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListEpcisJobs extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = EpcisJobResource::class;
}
