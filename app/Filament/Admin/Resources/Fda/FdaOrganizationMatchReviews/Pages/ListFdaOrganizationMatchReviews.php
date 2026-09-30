<?php

namespace App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\Pages;

use App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\FdaOrganizationMatchReviewResource;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListFdaOrganizationMatchReviews extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = FdaOrganizationMatchReviewResource::class;
}
