<?php

namespace App\Filament\App\Resources\Products\Tables;

use App\Enums\PartnerType;
use App\Filament\App\Resources\FdaProducts\Actions\AddFdaProductPackagesAction;
use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Fda\FdaProduct;
use App\Models\Product;
use App\Support\Catalog\DisplayName;
use App\Support\Fda\FdaRegistryStatus;
use App\Support\Gs1\Ndc;
use App\Support\MasterData\ProductComplianceStatus;
use App\Support\Scout\TenantModelSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->rx()
                ->whereHas('tradingPartners')
                ->with(['tradingPartners', 'tradingPartner']))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state)),
                TextColumn::make('ndc')
                    ->label('NDC')
                    ->searchable()
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->formatStateUsing(fn (?string $state, Product $record): ?string => Ndc::formatDisplay(
                        $record->package_ndc ?? $record->ndc11 ?? $state,
                    )),
                TextColumn::make('strength')->toggleable(),
                TextColumn::make('sourcing_paths')
                    ->label('Sourcing paths')
                    ->wrap()
                    ->state(function (Product $record): string {
                        $paths = $record->tradingPartners
                            ->pluck('name')
                            ->map(fn (?string $name): ?string => DisplayName::clean($name))
                            ->filter()
                            ->values()
                            ->all();

                        if ($record->tradingPartners->contains(
                            fn ($partner): bool => $partner->partner_type === PartnerType::Manufacturer,
                        )) {
                            $paths[] = 'Direct';
                        }

                        return $paths !== [] ? implode(', ', $paths) : '—';
                    }),
                TextColumn::make('distributor_skus')
                    ->label('Distributor SKUs')
                    ->wrap()
                    ->state(function (Product $record): string {
                        $skus = $record->tradingPartners
                            ->pluck('pivot.partner_item_number')
                            ->filter()
                            ->unique()
                            ->values()
                            ->all();

                        return $skus !== [] ? implode(', ', $skus) : '—';
                    }),
                TextColumn::make('compliance')
                    ->label('Compliance')
                    ->badge()
                    ->state(fn (Product $record): string => ProductComplianceStatus::label($record))
                    ->color(fn (string $state): string => ProductComplianceStatus::color($state)),
                TextColumn::make('dea_schedule')
                    ->label('DEA')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->state(function (Product $record): ?string {
                        $fda = $record->relationLoaded('fdaProduct')
                            ? $record->fdaProduct
                            : (filled($record->fda_product_id)
                                ? FdaProduct::query()->find($record->fda_product_id)
                                : null);

                        return FdaRegistryStatus::deaScheduleLabel($fda?->dea_schedule);
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'CII' => 'danger',
                        'CIII', 'CIV', 'CV' => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                TextColumn::make('is_active')
                    ->label('Active')
                    ->badge()
                    ->formatStateUsing(fn (?bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (?bool $state): string => $state ? 'success' : 'gray'),
            ])
            ->defaultSort('name')
            ->searchPlaceholder('Name, NDC, or GTIN')
            ->searchUsing(fn (Builder $query, string $search) => TenantModelSearch::constrain(
                $query,
                Product::class,
                $search,
                ['name', 'gtin', 'ndc', 'ndc11', 'package_ndc'],
            ))
            ->filters([
                TernaryFilter::make('is_active')->default(true),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->emptyStateHeading('No authorized products')
            ->emptyStateDescription('Add products from FDA Products or a trading partner.')
            ->emptyStateActions([
                AddFdaProductPackagesAction::make(),
            ])
            ->recordActions(RecordActionGroup::make([
                ViewAction::make(),
                EditAction::make(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    RegulatoryCompliance::apply(
                        DeleteBulkAction::make(),
                        'products_bulk_delete',
                        requireReason: true,
                    ),
                ]),
            ]);
    }
}
