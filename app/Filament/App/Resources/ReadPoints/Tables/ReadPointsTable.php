<?php

namespace App\Filament\App\Resources\ReadPoints\Tables;

use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use App\Support\Catalog\DisplayName;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state)),
                TextColumn::make('site.name')->label('Site')->sortable(),
                TextColumn::make('code')->searchable()->toggleable(),
                TextColumn::make('sgln')
                    ->label('SGLN')
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->toggleable(),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (?bool $state): string => $state ? 'success' : 'gray'),
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
