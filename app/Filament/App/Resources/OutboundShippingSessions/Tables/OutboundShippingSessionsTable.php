<?php

namespace App\Filament\App\Resources\OutboundShippingSessions\Tables;

use App\Actions\Shipping\DeleteOutboundShippingSession;
use App\Filament\Support\Floor\UnsubmittedSessionDeleteAction;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Support\Shipping\OutboundShippingSessionStatus;
use App\Support\Shipping\ShipLayout;
use App\Support\Tables\DistinctColumnOptions;
use App\Support\TenantFeatures;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class OutboundShippingSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['site', 'tradingPartner', 'principal']))
            ->columns([
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(function (?string $state, OutboundShippingSession $record): string {
                        $label = OutboundShippingSessionStatus::label($state);

                        return $record->isVoided() ? $label.' · Voided' : $label;
                    })
                    ->color(function (?string $state, OutboundShippingSession $record): string {
                        if ($record->isVoided()) {
                            return 'warning';
                        }

                        return match ($state) {
                            'completed' => 'success',
                            'in_progress' => 'info',
                            'open' => 'warning',
                            'cancelled' => 'gray',
                            default => 'gray',
                        };
                    })
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('status')
                            ->options(fn (): array => DistinctColumnOptions::of(OutboundShippingSession::class, 'status')),
                    ),
                TextColumn::make('site.name')
                    ->label('Ship from')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('site_id')
                            ->options(fn (): array => DistinctColumnOptions::related(
                                OutboundShippingSession::class,
                                'site_id',
                                Site::class,
                            )),
                    ),
                TextColumn::make('principal.name')
                    ->label('Principal')
                    ->placeholder('—')
                    ->toggleable()
                    ->visible(fn (): bool => TenantFeatures::forTenant(tenant())->supportsPrincipals())
                    ->columnFilter(ColumnFilter::select()->syncWith('principal_id')),
                TextColumn::make('tradingPartner.name')
                    ->label('Customer')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('trading_partner_id')
                            ->options(fn (): array => DistinctColumnOptions::related(
                                OutboundShippingSession::class,
                                'trading_partner_id',
                                TradingPartner::class,
                            )),
                    ),
                TextColumn::make('asn_number')
                    ->label('ASN')
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('asn_number')
                            ->options(fn (): array => DistinctColumnOptions::of(OutboundShippingSession::class, 'asn_number')),
                    ),
                TextColumn::make('confirmed_count')
                    ->label('Confirmed')
                    ->alignEnd()
                    ->sortable()
                    ->columnFilter(ColumnFilter::range()),
                TextColumn::make('opened_at')
                    ->dateTime()
                    ->sortable()
                    ->columnFilter(ColumnFilter::date()),
                TextColumn::make('completed_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(ColumnFilter::date()),
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
