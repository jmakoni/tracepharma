<?php

namespace App\Filament\Admin\Resources\CustomerOnboardings\Pages;

use App\Filament\Admin\Resources\CustomerOnboardings\CustomerOnboardingResource;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListCustomerOnboardings extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = CustomerOnboardingResource::class;
}
