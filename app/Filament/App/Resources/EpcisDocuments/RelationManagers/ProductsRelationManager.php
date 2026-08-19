<?php

namespace App\Filament\App\Resources\EpcisDocuments\RelationManagers;

use App\Filament\App\Resources\EpcisDocuments\Actions\AuthorizeMissingProductsAction;
use App\Filament\App\Resources\Products\ProductResource;
use App\Filament\Support\RecordActionGroup;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisFileProductSummary;
use App\Support\Catalog\DisplayName;
use App\Support\Gs1\Ndc;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ProductsRelationManager extends RelationManager
{
    /**
     * Dummy relationship for Filament RelationManager contract.
     * The table uses file-derived product summaries (not a HasMany).
     */
    protected static string $relationship = 'events';

    protected static ?string $title = 'Products';

    protected static bool $isBadgeDeferred = true;

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var EpcisDocument $ownerRecord */
        return number_format($ownerRecord->fileProductSummaries()->count());
    }

    public function table(Table $table): Table
    {
        /** @var EpcisDocument $document */
        $document = $this->getOwnerRecord();

        return $table
            ->records(fn (): Collection => $document->fileProductSummaries()
                ->mapWithKeys(function (array $row): array {
                    $model = new EpcisFileProductSummary;
                    $model->forceFill([
                        'id' => (string) $row['key'],
                        'gtin' => $row['gtin'],
                        'name' => $row['name'],
                        'ndc' => $row['ndc'],
                        'dosage_form' => $row['dosage_form'],
                        'strength' => $row['strength'],
                        'manufacturer' => $row['manufacturer'],
                        'document_epc_count' => $row['document_epc_count'],
                        'case_count' => $row['case_count'] ?? 0,
                        'unit_count' => $row['unit_count'] ?? 0,
                        'epc_breakdown' => $row['epc_breakdown'] ?? (string) $row['document_epc_count'],
                        'product_id' => $row['product_id'],
                        'linked' => $row['linked'],
                        'catalog_status' => $row['catalog_status'] ?? ($row['linked'] ? 'assortment' : 'none'),
                        'net_content' => $row['net_content'] ?? null,
                    ]);
                    $model->syncOriginal();
                    $model->exists = false;

                    return [(string) $row['key'] => $model];
                }))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state) ?? $state),
                TextColumn::make('ndc')
                    ->label('NDC')
                    ->searchable()
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—')
                    ->formatStateUsing(function (?string $state): ?string {
                        if ($state === null || $state === '') {
                            return null;
                        }

                        // Summary already prefers FDA/assortment package_ndc; helper
                        // still reverses HIPAA NDC-11 and keeps FDA 10-digit shapes.
                        return Ndc::formatPackageDisplay($state) ?? $state;
                    }),
                TextColumn::make('gtin')
                    ->label('GTINs')
                    ->searchable()
                    ->fontFamily(FontFamily::Mono)
                    ->wrap()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                TextColumn::make('manufacturer')
                    ->label('Manufacturer')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state)),
                TextColumn::make('dosage_form')
                    ->label('Dosage form')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('strength')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('net_content')
                    ->label('Net content')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('epc_breakdown')
                    ->label('EPCs in file')
                    ->alignEnd(),
                TextColumn::make('catalog_status')
                    ->label('Master data')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'assortment' => 'In assortment',
                        'fda' => 'FDA listed',
                        default => 'Unknown',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'assortment' => 'success',
                        'fda' => 'info',
                        default => 'warning',
                    }),
            ])
            ->defaultSort('name')
            ->recordAction(null)
            ->recordUrl(null)
            ->recordActions(RecordActionGroup::make([
                ViewAction::make()
                    ->visible(fn (Model $record): bool => filled($record->getAttribute('product_id')))
                    ->url(fn (Model $record): string => ProductResource::getUrl('view', [
                        'record' => $record->getAttribute('product_id'),
                    ])),
            ]))
            ->deferLoading()
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->headerActions([
                AuthorizeMissingProductsAction::make($this),
            ])
            ->modelLabel('Product')
            ->pluralModelLabel('Products');
    }
}
