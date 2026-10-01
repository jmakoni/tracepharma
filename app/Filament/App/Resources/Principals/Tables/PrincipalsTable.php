<?php

namespace App\Filament\App\Resources\Principals\Tables;

use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Principal;
use App\Support\Catalog\DisplayName;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class PrincipalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state))
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('name')
                            ->options(fn (): array => DistinctColumnOptions::of(Principal::class, 'name')),
                    ),
                TextColumn::make('gln')
                    ->label('GLN')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->fontFamily(FontFamily::Mono)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('gln')
                            ->options(fn (): array => DistinctColumnOptions::of(Principal::class, 'gln')),
                    ),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (?bool $state): string => $state ? 'success' : 'gray')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('is_active')
                            ->options(DistinctColumnOptions::boolean('Active', 'Inactive')),
                    ),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(ColumnFilter::date()),
            ])
            ->defaultSort('name')
            ->searchPlaceholder('Name or GLN')
            ->filters([
                TernaryFilter::make('is_active')->default(true),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                EditAction::make(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    RegulatoryCompliance::apply(
                        DeleteBulkAction::make(),
                        'principals_bulk_delete',
                        requireReason: true,
                    ),
                ]),
            ]);
    }
}
