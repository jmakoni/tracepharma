<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\OutboundNetworkProfiles\Pages;

use App\Filament\Admin\Resources\OutboundNetworkProfiles\OutboundNetworkProfileResource;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListOutboundNetworkProfiles extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = OutboundNetworkProfileResource::class;
}
