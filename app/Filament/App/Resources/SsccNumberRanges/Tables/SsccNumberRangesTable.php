<?php

namespace App\Filament\App\Resources\SsccNumberRanges\Tables;

use App\Enums\SsccNumberRangeScope;
use App\Enums\SsccNumberRangeStatus;
use App\Filament\App\Resources\Sites\RelationManagers\SsccNumberRangesRelationManager;
use App\Filament\App\Resources\Sites\SiteResource;
use App\Filament\Support\RecordActionGroup;
use App\Models\SsccNumberRange;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SsccNumberRangesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['site:id,name', 'tradingPartner:id,name']))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('scope')
                    ->badge()
                    ->formatStateUsing(fn (?SsccNumberRangeScope $state): ?string => $state?->label())
                    ->sortable(),
                TextColumn::make('owner')
                    ->label('Owner')
                    ->state(fn (SsccNumberRange $record): string => $record->ownerLabel())
                    ->url(function (SsccNumberRange $record): ?string {
                        if ($record->scope !== SsccNumberRangeScope::Site || $record->site_id === null) {
                            return null;
                        }

                        return SiteResource::getUrl('view', ['record' => $record->site_id], panel: 'app')
                            .'?relation='.(string) self::siteSsccRelationIndex();
                    })
                    ->color(fn (SsccNumberRange $record): ?string => $record->scope === SsccNumberRangeScope::Site
                        ? 'primary'
                        : null),
                TextColumn::make('company_prefix')
                    ->label('Prefix')
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('extension_digit')
                    ->label('Ext')
                    ->alignCenter(),
                TextColumn::make('index')
                    ->sortable(),
                TextColumn::make('remaining')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('threshold_percentage')
                    ->label('Threshold')
                    ->suffix('%')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?SsccNumberRangeStatus $state): ?string => $state?->label())
                    ->color(fn (?SsccNumberRangeStatus $state): string => $state?->badgeColor() ?? 'gray')
                    ->sortable(),
            ])
            ->defaultSort('index')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(SsccNumberRangeStatus::cases())->mapWithKeys(
                        fn (SsccNumberRangeStatus $status): array => [$status->value => $status->label()]
                    )),
                SelectFilter::make('scope')
                    ->options(collect(SsccNumberRangeScope::cases())->mapWithKeys(
                        fn (SsccNumberRangeScope $scope): array => [$scope->value => $scope->label()]
                    )),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                EditAction::make(),
                Action::make('markInactive')
                    ->label('Mark inactive')
                    ->icon('heroicon-o-pause-circle')
                    ->color('gray')
                    ->visible(fn (SsccNumberRange $record): bool => $record->status === SsccNumberRangeStatus::Active)
                    ->requiresConfirmation()
                    ->action(function (SsccNumberRange $record): void {
                        $record->markInactive();
                        Notification::make()->title('Range marked inactive')->success()->send();
                    }),
                Action::make('resetNotification')
                    ->label('Reset threshold alert')
                    ->icon('heroicon-o-bell-slash')
                    ->color('gray')
                    ->visible(fn (SsccNumberRange $record): bool => $record->threshold_notified_at !== null)
                    ->action(function (SsccNumberRange $record): void {
                        $record->forceFill(['threshold_notified_at' => null])->save();
                        Notification::make()->title('Threshold alert reset')->success()->send();
                    }),
            ]));
    }

    /**
     * Relation tab index for SSCC ranges on Site view (after devices + ATP).
     */
    private static function siteSsccRelationIndex(): int
    {
        $relations = SiteResource::getRelations();
        $index = array_search(
            SsccNumberRangesRelationManager::class,
            $relations,
            true,
        );

        return is_int($index) ? $index : 2;
    }
}
