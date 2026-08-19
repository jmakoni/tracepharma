<?php

namespace App\Filament\App\Resources\ActivityLogs\Pages;

use App\Filament\App\Resources\ActivityLogs\ActivityLogResource;
use Filament\Resources\Pages\ListRecords;

class ListActivityLogs extends ListRecords
{
    protected static string $resource = ActivityLogResource::class;
}
