<?php

namespace App\Filament\Admin\Resources\ConnectionRequests\Pages;

use App\Filament\Admin\Resources\ConnectionRequests\Actions\ReviewConnectionRequestActions;
use App\Filament\Admin\Resources\ConnectionRequests\ConnectionRequestResource;
use Filament\Resources\Pages\ViewRecord;

class ViewConnectionRequest extends ViewRecord
{
    protected static string $resource = ConnectionRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ReviewConnectionRequestActions::approve(),
            ReviewConnectionRequestActions::reject(),
            ReviewConnectionRequestActions::suspend(),
            ReviewConnectionRequestActions::resume(),
        ];
    }
}
