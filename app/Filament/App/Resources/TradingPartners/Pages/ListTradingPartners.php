<?php

namespace App\Filament\App\Resources\TradingPartners\Pages;

use App\Filament\App\Resources\TradingPartners\TradingPartnerResource;
use App\Filament\Support\TradingPartnerModalActions;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTradingPartners extends ListRecords
{
    protected static string $resource = TradingPartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TradingPartnerModalActions::create(CreateAction::make(), TradingPartnerResource::class, assignSlug: false),
        ];
    }
}
