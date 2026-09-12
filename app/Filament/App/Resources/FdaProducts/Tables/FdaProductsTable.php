<?php

namespace App\Filament\App\Resources\FdaProducts\Tables;

use App\Filament\App\Resources\FdaProducts\Actions\AddFdaProductPackagesAction;
use App\Filament\Support\RecordActionGroup;
use App\Models\Fda\FdaProduct;
use App\Support\Catalog\DisplayName;
use App\Support\Fda\FdaRegistryStatus;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FdaProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['activeIngredients', 'packaging', 'fdaOrganization']))
            ->columns([
                TextColumn::make('product_ndc')
                    ->label('NDC')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('brand_name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(function (?string $state, Model $record): string {
                        $brand = DisplayName::clean($state);
                        if (filled($brand)) {
                            return $brand;
                        }

                        $generic = DisplayName::clean($record->getAttribute('generic_name'));

                        return filled($generic) ? $generic : '—';
                    }),
                TextColumn::make('generic_name')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('dosage_form')
                    ->label('Dosage')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('dea_schedule')
                    ->label('DEA')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (?string $state): ?string => FdaRegistryStatus::deaScheduleLabel($state))
                    ->color(fn (?string $state): string => match (FdaRegistryStatus::deaScheduleLabel($state)) {
                        'CII' => 'danger',
                        'CIII', 'CIV', 'CV' => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                TextColumn::make('strength')
                    ->label('Strength')
                    ->wrap()
                    ->state(fn (FdaProduct $record): ?string => $record->activeIngredientStrength())
                    ->placeholder('—'),
                TextColumn::make('net_contents')
                    ->label('Net contents')
                    ->wrap()
                    ->state(fn (Model $record): string => $record->packaging
                        ->pluck('description')
                        ->filter()
                        ->unique()
                        ->values()
                        ->implode('; ') ?: '—'),
                TextColumn::make('fdaOrganization.name')
                    ->label('Labeler')
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state))
                    ->toggleable(),
            ])
            ->defaultSort('product_ndc')
            ->searchPlaceholder('NDC, brand, or generic name')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->emptyStateHeading('No FDA products yet')
            ->emptyStateDescription('Search Rx FDA NDCs and authorize packages for your partners.')
            ->emptyStateActions([
                AddFdaProductPackagesAction::make(),
            ])
            ->headerActions([
                AddFdaProductPackagesAction::make(),
            ])
            ->recordActions(RecordActionGroup::make([
                ViewAction::make(),
            ]));
    }
}
