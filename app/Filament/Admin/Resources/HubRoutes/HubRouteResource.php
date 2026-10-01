<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\HubRoutes;

use App\Filament\Admin\Resources\HubRoutes\Pages\ListHubRoutes;
use App\Models\Admin;
use App\Models\EpcisHubRoute;
use App\Models\Tenant;
use App\Support\Auth\Permissions;
use App\Support\Tables\DistinctColumnOptions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class HubRouteResource extends Resource
{
    protected static ?string $model = EpcisHubRoute::class;

    protected static ?string $slug = 'hub-directory';

    protected static ?string $navigationLabel = 'Hub directory';

    protected static ?string $modelLabel = 'Hub route';

    protected static ?string $pluralModelLabel = 'Hub routes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Tenants / Hub';

    protected static ?int $navigationSort = 21;

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin && $admin->can(Permissions::TenantsManage);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('gln')
            ->columns([
                TextColumn::make('gln')
                    ->label('Receiver GLN')
                    ->searchable()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('gln')
                            ->options(fn (): array => DistinctColumnOptions::of(EpcisHubRoute::class, 'gln')),
                    ),
                TextColumn::make('provider')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->columnFilter(ColumnFilter::select()->syncWith('provider')),
                TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->placeholder('—')
                    ->searchable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('tenant_id')
                            ->options(fn (): array => DistinctColumnOptions::related(
                                EpcisHubRoute::class,
                                'tenant_id',
                                Tenant::class,
                            )),
                    ),
                TextColumn::make('tenant.inbound_environment')
                    ->label('Environment')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('claimed_via')
                    ->label('Claimed via')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === EpcisHubRoute::CLAIMED_VIA_ADMIN ? 'Admin' : 'Connection')
                    ->color(fn (string $state): string => $state === EpcisHubRoute::CLAIMED_VIA_ADMIN ? 'info' : 'gray')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('claimed_via')
                            ->options(fn (): array => DistinctColumnOptions::of(EpcisHubRoute::class, 'claimed_via')),
                    ),
                TextColumn::make('default_inbound_connection_id')
                    ->label('Default connection')
                    ->placeholder('—')
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('default_inbound_connection_id')
                            ->options(fn (): array => DistinctColumnOptions::of(EpcisHubRoute::class, 'default_inbound_connection_id')),
                    ),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('is_active')
                            ->options(DistinctColumnOptions::boolean('Active', 'Inactive')),
                    ),
                TextColumn::make('last_routed_at')
                    ->label('Last routed')
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable()
                    ->columnFilter(ColumnFilter::date()),
            ])
            ->filters([
                SelectFilter::make('provider')
                    ->options(fn (): array => EpcisHubRoute::query()
                        ->distinct()
                        ->orderBy('provider')
                        ->pluck('provider', 'provider')
                        ->all()),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                Action::make('toggleActive')
                    ->label(fn (EpcisHubRoute $record): string => $record->is_active ? 'Deactivate' : 'Reactivate')
                    ->icon(fn (EpcisHubRoute $record): string => $record->is_active ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
                    ->color(fn (EpcisHubRoute $record): string => $record->is_active ? 'gray' : 'success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (EpcisHubRoute $record): string => $record->is_active
                        ? 'Deactivating stops hub routing for this GLN immediately — inbound documents addressed to it will be rejected.'
                        : 'Reactivating resumes hub routing for this GLN.')
                    ->action(function (EpcisHubRoute $record): void {
                        $record->forceFill(['is_active' => ! $record->is_active])->save();

                        Notification::make()
                            ->title($record->is_active ? 'Route reactivated' : 'Route deactivated')
                            ->body("{$record->provider} / {$record->gln}")
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHubRoutes::route('/'),
        ];
    }
}
