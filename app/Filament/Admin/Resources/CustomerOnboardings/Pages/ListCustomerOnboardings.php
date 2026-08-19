<?php

namespace App\Filament\Admin\Resources\CustomerOnboardings\Pages;

use App\Filament\Admin\Resources\CustomerOnboardings\CustomerOnboardingResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerOnboardings extends ListRecords
{
    protected static string $resource = CustomerOnboardingResource::class;
}
