<?php

namespace App\Filament\Admin\Resources\DemoRequests\Pages;

use App\Filament\Admin\Resources\DemoRequests\DemoRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListDemoRequests extends ListRecords
{
    protected static string $resource = DemoRequestResource::class;
}
