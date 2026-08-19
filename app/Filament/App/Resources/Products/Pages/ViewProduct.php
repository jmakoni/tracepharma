<?php

namespace App\Filament\App\Resources\Products\Pages;

use App\Filament\App\Resources\Products\ProductResource;
use App\Filament\Support\RegulatoryCompliance;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->loadMissing(['tradingPartner', 'tradingPartners']);
    }

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
            RegulatoryCompliance::apply(
                EditAction::make()
                    ->label('Edit product')
                    ->color('primary')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modal()
                    ->modalWidth(Width::FiveExtraLarge),
                'products_edit',
                requireReason: false,
            ),
        ];
    }
}
