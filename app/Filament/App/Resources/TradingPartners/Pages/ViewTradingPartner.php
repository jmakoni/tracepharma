<?php

namespace App\Filament\App\Resources\TradingPartners\Pages;

use App\Filament\App\Resources\TradingPartners\Actions\RecordAtpVerificationAction;
use App\Filament\App\Resources\TradingPartners\TradingPartnerResource;
use App\Filament\Support\TradingPartnerModalActions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewTradingPartner extends ViewRecord
{
    protected static string $resource = TradingPartnerResource::class;

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getRecordTitle();
    }

    protected function getHeaderActions(): array
    {
        return [
            TradingPartnerModalActions::edit(
                EditAction::make()
                    ->label('Edit partner')
                    ->color('primary')
                    ->icon(Heroicon::OutlinedPencilSquare),
                lockSlug: false,
            ),
            RecordAtpVerificationAction::make(),
        ];
    }
}
