<?php

namespace App\Filament\App\Resources\EpcisDocuments\RelationManagers;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Support\Scout\TenantModelSearch;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'activeEvents';

    protected static ?string $title = 'Events';

    protected static bool $isBadgeDeferred = true;

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var EpcisDocument $ownerRecord */
        return number_format((int) $ownerRecord->event_count);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('event_time')
                    ->label('Event time')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('event_type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('action')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('biz_step')
                    ->label('Biz step')
                    ->formatStateUsing(fn (?string $state): ?string => self::stripUrnPrefix($state))
                    ->limit(28)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->toggleable(),
                TextColumn::make('disposition')
                    ->formatStateUsing(fn (?string $state): ?string => self::stripUrnPrefix($state))
                    ->limit(28)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->toggleable(),
                TextColumn::make('read_point_gln')
                    ->label('Read point')
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('event_epcs_count')
                    ->label('EPCs')
                    ->counts('eventEpcs')
                    ->alignEnd()
                    ->sortable(),
            ])
            ->defaultSort('event_time')
            ->searchPlaceholder('Event id, biz step, action, or read point')
            ->searchUsing(fn (Builder $query, string $search) => TenantModelSearch::constrain(
                $query,
                EpcisEvent::class,
                $search,
                ['event_id', 'biz_step', 'action', 'read_point_gln'],
            ))
            ->filters([
                SelectFilter::make('event_type')
                    ->label('Type')
                    ->options(fn (): array => $this->getOwnerRecord()
                        ->activeEvents()
                        ->whereNotNull('event_type')
                        ->distinct()
                        ->orderBy('event_type')
                        ->pluck('event_type', 'event_type')
                        ->all()),
                SelectFilter::make('action')
                    ->options([
                        'ADD' => 'ADD',
                        'OBSERVE' => 'OBSERVE',
                        'DELETE' => 'DELETE',
                    ]),
                SelectFilter::make('biz_step')
                    ->label('Biz step')
                    ->options(fn (): array => $this->distinctUrnOptions('biz_step')),
                SelectFilter::make('disposition')
                    ->options(fn (): array => $this->distinctUrnOptions('disposition')),
                Filter::make('read_point_gln')
                    ->schema([
                        TextInput::make('value')
                            ->label('Read point GLN')
                            ->placeholder('Digits only'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $gln = preg_replace('/\D+/', '', (string) ($data['value'] ?? '')) ?? '';

                        if ($gln === '') {
                            return $query;
                        }

                        return $query->where('read_point_gln', $gln);
                    }),
                Filter::make('biz_location_gln')
                    ->schema([
                        TextInput::make('value')
                            ->label('Biz location GLN')
                            ->placeholder('Digits only'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $gln = preg_replace('/\D+/', '', (string) ($data['value'] ?? '')) ?? '';

                        if ($gln === '') {
                            return $query;
                        }

                        return $query->where('biz_location_gln', $gln);
                    }),
            ], FiltersLayout::Modal)
            ->filtersFormColumns(3)
            ->filtersFormWidth(Width::FourExtraLarge)
            ->deferLoading()
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->headerActions([])
            ->recordActions([]);
    }

    /**
     * @return array<string, string>
     */
    private function distinctUrnOptions(string $column): array
    {
        return $this->getOwnerRecord()
            ->activeEvents()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->mapWithKeys(fn (string $value): array => [$value => (string) self::stripUrnPrefix($value)])
            ->all();
    }

    private static function stripUrnPrefix(?string $state): ?string
    {
        if ($state === null || $state === '') {
            return $state;
        }

        return (string) str($state)->afterLast(':');
    }
}
