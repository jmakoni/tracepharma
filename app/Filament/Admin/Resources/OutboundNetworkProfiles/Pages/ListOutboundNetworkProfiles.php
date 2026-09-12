<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\OutboundNetworkProfiles\Pages;

use App\Filament\Admin\Resources\OutboundNetworkProfiles\OutboundNetworkProfileResource;
use Filament\Resources\Pages\ListRecords;

class ListOutboundNetworkProfiles extends ListRecords
{
    protected static string $resource = OutboundNetworkProfileResource::class;
}
