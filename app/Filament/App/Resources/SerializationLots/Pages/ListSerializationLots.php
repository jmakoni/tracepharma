<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\SerializationLots\Pages;

use App\Filament\App\Resources\SerializationLots\SerializationLotResource;
use Filament\Resources\Pages\ListRecords;

class ListSerializationLots extends ListRecords
{
    protected static string $resource = SerializationLotResource::class;
}
