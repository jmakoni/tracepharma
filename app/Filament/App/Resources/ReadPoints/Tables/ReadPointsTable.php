<?php

namespace App\Filament\App\Resources\ReadPoints\Tables;

use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\ReadPoint;
use App\Models\Site;
use App\Support\Catalog\DisplayName;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class ReadPointsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('site'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state))
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('name')
                            ->options(fn (): array => DistinctColumnOptions::of(ReadPoint::class, 'name')),
                    ),
                TextColumn::make('site.name')
                    ->label('Site')
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('site_id')
                            ->options(fn (): array => DistinctColumnOptions::related(
                                ReadPoint::class,
                                'site_id',
                                Site::class,
                            )),
                    ),
                TextColumn::make('code')
                    ->searchable()
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('code')
                            ->options(fn (): array => DistinctColumnOptions::of(ReadPoint::class, 'code')),
                    ),
                TextColumn::make('sgln')
                    ->label('SGLN')
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('sgln')
                            ->options(fn (): array => DistinctColumnOptions::of(ReadPoint::class, 'sgln')),
                    ),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (?bool $state): string => $state ? 'success' : 'gray')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('is_active')
                            ->options(DistinctColumnOptions::boolean()),
                    ),
            ])
            ->defaultSort('name')
            ->searchPlaceholder('Name, code, SGLN, or site')
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
                        'read_points_bulk_delete',
                        requireReason: true,
                    ),
                ]),
            ]);
    }
}
