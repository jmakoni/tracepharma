<?php

namespace App\Filament\App\Resources\OutboundShippingSessions\Tables;

use App\Models\Shipping\OutboundShippingSession;
use App\Support\Shipping\OutboundShippingSessionStatus;
use App\Support\Shipping\ShipLayout;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OutboundShippingSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['site', 'tradingPartner']))
            ->columns([
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => OutboundShippingSessionStatus::label($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'completed' => 'success',
                        'in_progress' => 'info',
                        'open' => 'warning',
                        'cancelled' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('site.name')
                    ->label('Ship from')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tradingPartner.name')
                    ->label('Customer')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('asn_number')
                    ->label('ASN')
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('confirmed_count')
                    ->label('Confirmed')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('opened_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make()
                    ->url(fn (OutboundShippingSession $record): string => ShipLayout::sessionUrl($record)),
            ]);
    }
}
