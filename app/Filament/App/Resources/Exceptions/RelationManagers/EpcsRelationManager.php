<?php

namespace App\Filament\App\Resources\Exceptions\RelationManagers;

use App\Models\Epcis\Epc;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Fda\FdaProductPackaging;
use App\Support\Catalog\DisplayName;
use App\Support\Epcis\ShipmentReference;
use App\Support\Exceptions\AssortmentFromCatalog;
use App\Support\Gs1\Gtin;
use App\Support\Tracing\AssetTrackingUrl;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EpcsRelationManager extends RelationManager
{
    protected static string $relationship = 'epcs';

    protected static ?string $title = 'EPCs';

    /** @var array<string, ?FdaProductPackaging> */
    private array $packagingByGtinCache = [];

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        /** @var ExceptionCase $owner */
        $owner = $this->getOwnerRecord();
        $owner->loadMissing(['document:id,customer_po,asn_number,original_filename']);
        $po = ShipmentReference::po($owner->document);
        $statusLabel = $owner->status?->label() ?? '—';

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'product' => fn ($q) => $q->select(['id', 'name', 'ndc', 'package_ndc', 'ndc11']),
                    'ilmd',
                ])
                ->withCount([
                    'aggregationLinksAsParent as open_child_count' => fn (Builder $q): Builder => $q->whereNull('valid_to'),
                ]))
            ->columns([
                TextColumn::make('po')
                    ->label('PO')
                    ->state(fn (): string => $po)
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('ndc')
                    ->label('NDC')
                    ->state(fn (Epc $record): string => $this->ndcFor($record))
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('product', function (Builder $q) use ($search): void {
                            $q->where('package_ndc', 'like', "%{$search}%")
                                ->orWhere('ndc11', 'like', "%{$search}%")
                                ->orWhere('ndc', 'like', "%{$search}%");
                        });
                    }),
                TextColumn::make('product_name')
                    ->label('Item / product name')
                    ->state(fn (Epc $record): string => $this->productNameFor($record))
                    ->limit(40)
                    ->tooltip(fn (Epc $record): ?string => ($name = $this->productNameFor($record)) !== '—' ? $name : null)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('product', fn (Builder $q): Builder => $q->where('name', 'like', "%{$search}%"));
                    }),
                AssetTrackingUrl::linkEpcColumn(
                    TextColumn::make('gtin14')
                        ->label('GTIN')
                        ->state(fn (Epc $record): string => filled($record->gtin14)
                            ? (string) $record->gtin14
                            : (filled($record->sscc18) ? (string) $record->sscc18 : '—'))
                        ->fontFamily(FontFamily::Mono)
                        ->searchable(['gtin14', 'sscc18']),
                    fn (mixed $record): ?Epc => $record instanceof Epc ? $record : null,
                    copyable: true,
                ),
                AssetTrackingUrl::linkEpcColumn(
                    TextColumn::make('serial_number')
                        ->label('Serial #')
                        ->placeholder('—')
                        ->fontFamily(FontFamily::Mono)
                        ->searchable(),
                    fn (mixed $record): ?Epc => $record instanceof Epc ? $record : null,
                    copyable: true,
                ),
                TextColumn::make('ilmd.lot_number')
                    ->label('Lot #')
                    ->placeholder('—')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('ilmd', fn (Builder $q): Builder => $q->where('lot_number', 'like', "%{$search}%"));
                    }),
                TextColumn::make('ilmd.expiry_date')
                    ->label('Exp')
                    ->date('Y-m-d')
                    ->placeholder('—'),
                TextColumn::make('quantity')
                    ->label('Quantity')
                    ->alignEnd()
                    ->state(function (Epc $record): int {
                        $childCount = (int) ($record->getAttribute('open_child_count') ?? 0);

                        return $childCount > 0 ? $childCount : 1;
                    }),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (): string => $statusLabel),
            ])
            ->paginated([50, 100, 250])
            ->defaultPaginationPageOption(50)
            ->headerActions([])
            ->recordActions([])
            ->emptyStateHeading('No EPCs')
            ->emptyStateDescription('This exception case has no linked serials.');
    }

    private function ndcFor(Epc $record): string
    {
        $product = $record->product;
        $ndc = filled($product?->package_ndc)
            ? (string) $product->package_ndc
            : (filled($product?->ndc11) ? (string) $product->ndc11 : (filled($product?->ndc) ? (string) $product->ndc : null));

        if ($ndc !== null) {
            return $ndc;
        }

        $packaging = $this->packagingFor($record);

        return filled($packaging?->package_ndc)
            ? (string) $packaging->package_ndc
            : (filled($packaging?->ndc11) ? (string) $packaging->ndc11 : '—');
    }

    private function productNameFor(Epc $record): string
    {
        if (filled($record->product?->name) && strcasecmp((string) $record->product->name, 'N/A') !== 0) {
            return (string) $record->product->name;
        }

        $packaging = $this->packagingFor($record);
        $listing = $packaging?->product;
        $name = DisplayName::clean($listing?->name ?: $listing?->brand_name ?: $listing?->generic_name);

        return filled($name) && strcasecmp($name, 'N/A') !== 0
            ? $name
            : '—';
    }

    private function packagingFor(Epc $record): ?FdaProductPackaging
    {
        $candidates = [];
        if (filled($record->gtin14)) {
            $candidates[] = (string) $record->gtin14;
        }

        if (filled($record->company_prefix) && filled($record->item_reference)) {
            $body13 = '0'.$record->company_prefix.$record->item_reference;
            if (strlen($body13) === 13 && ctype_digit($body13)) {
                $candidates[] = $body13.Gtin::checkDigit($body13);
            }
        } elseif (filled($record->gtin14) && strlen((string) $record->gtin14) === 14 && ctype_digit((string) $record->gtin14)) {
            $body13 = '0'.substr((string) $record->gtin14, 1, 12);
            if (ctype_digit($body13)) {
                $candidates[] = $body13.Gtin::checkDigit($body13);
            }
        }

        foreach (array_unique($candidates) as $gtin) {
            if (! array_key_exists($gtin, $this->packagingByGtinCache)) {
                $this->packagingByGtinCache[$gtin] = AssortmentFromCatalog::findPackagingByGtin($gtin);
            }

            if ($this->packagingByGtinCache[$gtin] !== null) {
                return $this->packagingByGtinCache[$gtin];
            }
        }

        return null;
    }
}
