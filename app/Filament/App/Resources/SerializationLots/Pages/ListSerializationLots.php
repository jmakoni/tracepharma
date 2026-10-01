<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\SerializationLots\Pages;

use App\Filament\App\Resources\SerializationLots\SerializationLotResource;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListSerializationLots extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = SerializationLotResource::class;
}
