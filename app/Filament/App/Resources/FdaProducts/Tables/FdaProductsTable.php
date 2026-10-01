<?php

namespace App\Filament\App\Resources\FdaProducts\Tables;

use App\Filament\App\Resources\FdaProducts\Actions\AddFdaProductPackagesAction;
use App\Filament\Support\RecordActionGroup;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Support\Catalog\DisplayName;
use App\Support\Fda\FdaRegistryStatus;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

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
                    ->fontFamily(FontFamily::Mono)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('product_ndc')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaProduct::class, 'product_ndc')),
                    ),
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
                    })
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('brand_name')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaProduct::class, 'brand_name')),
                    ),
                TextColumn::make('generic_name')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('generic_name')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaProduct::class, 'generic_name')),
                    ),
                TextColumn::make('dosage_form')
                    ->label('Dosage')
                    ->searchable()
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('dosage_form')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaProduct::class, 'dosage_form')),
                    ),
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
                    ->placeholder('—')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('dea_schedule')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaProduct::class, 'dea_schedule')),
                    ),
                TextColumn::make('strength')
                    ->label('Strength')
                    ->wrap()
                    ->state(fn (FdaProduct $record): ?string => $record->activeIngredientStrength())
                    ->placeholder('—')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->options(fn (): array => self::distinctDisplayedStrengthOptions())
                            ->applyUsing(fn (Builder $query, array $data): Builder => self::applyDisplayedStrengthFilter($query, $data)),
                    ),
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
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('fda_organization_id')
                            ->options(fn (): array => DistinctColumnOptions::related(
                                FdaProduct::class,
                                'fda_organization_id',
                                FdaOrganization::class,
                            )),
                    ),
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

    /**
     * @return array<string, string>
     */
    private static function distinctDisplayedStrengthOptions(int $limit = 250): array
    {
        $options = [];

        foreach (
            FdaProduct::query()
                ->with(['activeIngredients' => fn (Builder $query): Builder => $query->orderBy('id')])
                ->orderBy('product_ndc')
                ->lazy(100) as $product
        ) {
            $strength = $product->activeIngredientStrength();

            if (blank($strength)) {
                continue;
            }

            $options[$strength] = $strength;

            if (count($options) >= $limit) {
                break;
            }
        }

        natcasesort($options);

        return $options;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function applyDisplayedStrengthFilter(Builder $query, array $data): Builder
    {
        $values = array_values(array_filter(
            (array) ($data['values'] ?? $data['value'] ?? []),
            fn (mixed $value): bool => filled($value),
        ));

        if ($values === []) {
            return $query;
        }

        $matchingIds = [];

        foreach (
            FdaProduct::query()
                ->select('id')
                ->with(['activeIngredients' => fn (Builder $query): Builder => $query->orderBy('id')])
                ->lazyById(200) as $product
        ) {
            $strength = $product->activeIngredientStrength();

            if ($strength !== null && in_array($strength, $values, true)) {
                $matchingIds[] = $product->id;
            }
        }

        return $query->whereIn($query->getModel()->getQualifiedKeyName(), $matchingIds);
    }
}
