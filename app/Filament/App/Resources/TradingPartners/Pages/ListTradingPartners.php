<?php

namespace App\Filament\App\Resources\TradingPartners\Pages;

use App\Filament\App\Resources\TradingPartners\TradingPartnerResource;
use App\Filament\Support\TradingPartnerModalActions;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Zvizvi\FilamentColumnFilters\Concerns\HasColumnFilters;

class ListTradingPartners extends ListRecords
{
    use HasColumnFilters;

    protected static string $resource = TradingPartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TradingPartnerModalActions::create(CreateAction::make(), TradingPartnerResource::class, assignSlug: false),
        ];
    }
}
