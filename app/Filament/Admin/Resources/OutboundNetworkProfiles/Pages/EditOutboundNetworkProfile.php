<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\OutboundNetworkProfiles\Pages;

use App\Filament\Admin\Resources\OutboundNetworkProfiles\OutboundNetworkProfileResource;
use Filament\Resources\Pages\EditRecord;

class EditOutboundNetworkProfile extends EditRecord
{
    protected static string $resource = OutboundNetworkProfileResource::class;
}
