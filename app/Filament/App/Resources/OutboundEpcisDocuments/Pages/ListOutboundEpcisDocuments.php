<?php

namespace App\Filament\App\Resources\OutboundEpcisDocuments\Pages;

use App\Filament\App\Resources\OutboundEpcisDocuments\OutboundEpcisDocumentResource;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListOutboundEpcisDocuments extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = OutboundEpcisDocumentResource::class;
}
