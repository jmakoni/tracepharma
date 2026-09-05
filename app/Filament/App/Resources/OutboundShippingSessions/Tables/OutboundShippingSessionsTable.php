<?php

namespace App\Filament\App\Resources\OutboundShippingSessions\Tables;

use App\Actions\Shipping\DeleteOutboundShippingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Filament\Support\Floor\UnsubmittedSessionDeleteAction;
use App\Support\Shipping\OutboundShippingSessionStatus;
use App\Support\Shipping\ShipLayout;
use App\Support\TenantFeatures;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OutboundShippingSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['site', 'tradingPartner', 'principal']))
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
                TextColumn::make('principal.name')
                    ->label('Principal')
                    ->placeholder('—')
                    ->toggleable()
                    ->visible(fn (): bool => TenantFeatures::forTenant(tenant())->supportsPrincipals()),
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
            ->filters([
                SelectFilter::make('principal_id')
                    ->label('Principal')
                    ->relationship('principal', 'name')
                    ->searchable()
                    ->preload()
                    ->visible(fn (): bool => TenantFeatures::forTenant(tenant())->supportsPrincipals()),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (OutboundShippingSession $record): string => ShipLayout::sessionUrl($record)),
                UnsubmittedSessionDeleteAction::forShipping(
                    fn (OutboundShippingSession $record) => app(DeleteOutboundShippingSession::class)->handle($record, auth()->id()),
                    '',
                ),
            ]);
    }
}
