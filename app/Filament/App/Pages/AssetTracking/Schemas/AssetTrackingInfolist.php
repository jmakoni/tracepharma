<?php

namespace App\Filament\App\Pages\AssetTracking\Schemas;

use App\Filament\App\Pages\AssetTracking;
use App\Services\Tracing\BuildAssetTrace;
use App\Support\L3\FormatKeyValueMap;
use App\Support\Tracing\CbvStatusColor;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

/**
 * Infolist sections for the {@see AssetTracking} page's "found" state.
 *
 * The page has no bound record, so every entry reads from the page's
 * `$trace` array (the {@see BuildAssetTrace} payload)
 * via `state()` closures against the page instance passed to
 * {@see self::components()}.
 */
class AssetTrackingInfolist
{
    /**
     * @return list<Component>
     */
    public static function components(AssetTracking $page): array
    {
        return [
            Grid::make(['default' => 1, 'md' => 2])
                ->schema([
                    self::identitySection($page),
                    self::technicalSection($page),
                ]),
            self::extendedProductDataSection($page),
        ];
    }

    private static function extendedProductDataSection(AssetTracking $page): Section
    {
        return Section::make('Extended Product Data')
            ->compact()
            ->schema([
                self::extendedTabs($page),
            ]);
    }

    private static function identitySection(AssetTracking $page): Section
    {
        return Section::make('Status')
            ->compact()
            ->columns(['md' => 2])
            ->schema([
                TextEntry::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (): ?string => $page->trace['status'] ?? null)
                    ->color(fn (): string => match ($page->trace['status_tone'] ?? null) {
                        'ok' => 'success',
                        'warn' => 'warning',
                        default => 'danger',
                    }),
                TextEntry::make('primary_identifier')
                    ->label('Primary identifier')
                    ->state(fn (): ?string => $page->trace['primary_identifier'] ?? null)
                    ->fontFamily(FontFamily::Mono)
                    ->size(TextSize::Large)
                    ->weight(FontWeight::Bold)
                    ->copyable()
                    ->placeholder('—'),
                TextEntry::make('disposition')
                    ->label('Disposition')
                    ->badge()
                    ->state(fn (): ?string => $page->trace['disposition'] ?? null)
                    ->color(fn (): string => CbvStatusColor::disposition(
                        $page->trace['disposition_uri'] ?? $page->trace['disposition'] ?? null
                    ))
                    ->placeholder('—'),
                TextEntry::make('disposition_at')
                    ->label('Disposition date')
                    ->state(fn (): ?string => $page->trace['disposition_at'] ?? null)
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('last_seen_at')
                    ->label('Last seen at')
                    ->state(fn (): ?string => $page->trace['last_seen_at'] ?? null)
                    ->placeholder('—'),
                TextEntry::make('children_count')
                    ->label('Contained units')
                    ->state(fn (): int => (int) ($page->trace['children_count'] ?? 0))
                    ->numeric()
                    ->visible(fn (): bool => (int) ($page->trace['children_count'] ?? 0) > 0),
                TextEntry::make('parent_identifier')
                    ->label('Parent')
                    ->state(fn (): ?string => $page->trace['parent']['primary_identifier']
                        ?? $page->trace['parent']['gs1_barcode']
                        ?? null)
                    ->fontFamily(FontFamily::Mono)
                    ->url(fn (): ?string => $page->trace['parent']['url'] ?? null)
                    ->color(fn (): string => filled($page->trace['parent']['url'] ?? null) ? 'primary' : 'gray')
                    ->visible(fn (): bool => ($page->trace['parent'] ?? null) !== null)
                    ->placeholder('—'),
                TextEntry::make('hud_product_name')
                    ->label('Product')
                    ->state(fn (): ?string => $page->trace['product']['name'] ?? null)
                    ->placeholder('—')
                    ->visible(fn (): bool => filled($page->trace['product']['name'] ?? null)),
                TextEntry::make('hud_product_ndc')
                    ->label('NDC')
                    ->state(fn (): ?string => $page->trace['product']['ndc'] ?? null)
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->placeholder('—')
                    ->visible(fn (): bool => filled($page->trace['product']['ndc'] ?? null)),
                TextEntry::make('hud_lot_number')
                    ->label('Lot')
                    ->state(fn (): ?string => $page->trace['lot']['lot_number'] ?? null)
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->placeholder('—')
                    ->visible(fn (): bool => filled($page->trace['lot']['lot_number'] ?? null)),
                TextEntry::make('hud_lot_expiry')
                    ->label('Expiry')
                    ->state(fn (): ?string => $page->trace['lot']['expiry_date'] ?? null)
                    ->date()
                    ->placeholder('—')
                    ->visible(fn (): bool => filled($page->trace['lot']['expiry_date'] ?? null)),
            ]);
    }

    private static function technicalSection(AssetTracking $page): Section
    {
        return Section::make('Identifiers & technical')
            ->compact()
            ->columns(['md' => 2])
            ->schema([
                TextEntry::make('gs1_barcode')
                    ->label('GS1 barcode')
                    ->state(fn (): ?string => $page->trace['gs1_barcode'] ?? null)
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->placeholder('—'),
                TextEntry::make('urn')
                    ->label('URN')
                    ->state(fn (): ?string => $page->trace['urn'] ?? null)
                    ->fontFamily(FontFamily::Mono)
                    ->limit(48)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->copyable()
                    ->placeholder('—'),
                TextEntry::make('disposition_uri')
                    ->label('Disposition URI')
                    ->state(fn (): ?string => $page->trace['disposition_uri'] ?? null)
                    ->fontFamily(FontFamily::Mono)
                    ->size(TextSize::ExtraSmall)
                    ->color('gray')
                    ->placeholder('—'),
                TextEntry::make('container_type')
                    ->label('Container type')
                    ->state(fn (): ?string => $page->trace['container_type'] ?? null)
                    ->placeholder('—'),
                TextEntry::make('serial_number')
                    ->label('Serial number')
                    ->state(fn (): ?string => $page->trace['serial_number'] ?? null)
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->placeholder('—'),
            ]);
    }

    private static function extendedTabs(AssetTracking $page): Tabs
    {
        return Tabs::make('Extended')
            ->columnSpanFull()
            ->tabs([
                Tab::make('Product')
                    ->columns(['md' => 2])
                    ->schema(self::productEntries($page)),
                Tab::make('Batch / Lot')
                    ->columns(['md' => 2])
                    ->schema(self::lotEntries($page)),
                Tab::make('Parties / Origin')
                    ->columns(['md' => 2])
                    ->schema(self::partyEntries($page)),
                Tab::make('Fields')
                    ->visible(fn (): bool => $page->containerField() !== null)
                    ->columns(['md' => 2])
                    ->schema(self::fieldsEntries($page)),
            ]);
    }

    /**
     * Guardian L3 per-container fields (GS1_XML / RawSeq / URI / ...) for the
     * currently traced EPC — {@see AssetTracking::containerField()}.
     *
     * @return list<Component>
     */
    private static function fieldsEntries(AssetTracking $page): array
    {
        return [
            TextEntry::make('container_lot_number')
                ->label('Lot number')
                ->state(fn (): ?string => $page->containerField()?->lot?->lot_number)
                ->fontFamily(FontFamily::Mono)
                ->url(fn (): ?string => $page->containerFieldLotUrl())
                ->color(fn (): string => filled($page->containerFieldLotUrl()) ? 'primary' : 'gray')
                ->copyable()
                ->placeholder('—'),
            TextEntry::make('container_type')
                ->label('Container type')
                ->state(fn (): ?string => $page->containerField()?->container_type)
                ->placeholder('—'),
            KeyValueEntry::make('container_fields')
                ->label('')
                ->keyLabel('Field')
                ->valueLabel('Value')
                ->columnSpanFull()
                ->state(fn (): array => FormatKeyValueMap::withNaPlaceholders($page->containerField()?->fields))
                ->placeholder('No Guardian fields recorded for this unit.'),
        ];
    }

    /**
     * @return list<Component>
     */
    private static function productEntries(AssetTracking $page): array
    {
        return [
            TextEntry::make('product_name')
                ->label('Name')
                ->state(fn (): ?string => $page->trace['product']['name'] ?? null)
                ->placeholder('—')
                ->columnSpanFull(),
            TextEntry::make('product_description')
                ->label('Description')
                ->state(fn (): ?string => $page->trace['product']['description'] ?? null)
                ->placeholder('—')
                ->columnSpanFull(),
            TextEntry::make('product_ndc')
                ->label('NDC')
                ->state(fn (): ?string => $page->trace['product']['ndc'] ?? null)
                ->fontFamily(FontFamily::Mono)
                ->copyable()
                ->placeholder('—'),
            TextEntry::make('product_gtin')
                ->label('GTIN')
                ->state(fn (): ?string => $page->trace['product']['gtin'] ?? null)
                ->fontFamily(FontFamily::Mono)
                ->copyable()
                ->placeholder('—'),
            TextEntry::make('product_dosage_form')
                ->label('Dosage form')
                ->state(fn (): ?string => $page->trace['product']['dosage_form'] ?? null)
                ->placeholder('—'),
            TextEntry::make('product_strength')
                ->label('Strength')
                ->state(fn (): ?string => $page->trace['product']['strength'] ?? null)
                ->placeholder('—'),
            TextEntry::make('product_package_ndc')
                ->label('Package NDC')
                ->state(fn (): ?string => $page->trace['product']['package_ndc'] ?? null)
                ->fontFamily(FontFamily::Mono)
                ->copyable()
                ->placeholder('—'),
            TextEntry::make('product_is_active')
                ->label('Active')
                ->state(fn (): ?string => match ($page->trace['product']['is_active'] ?? null) {
                    true => 'Yes',
                    false => 'No',
                    default => null,
                })
                ->placeholder('—'),
        ];
    }

    /**
     * @return list<Component>
     */
    private static function lotEntries(AssetTracking $page): array
    {
        return [
            TextEntry::make('lot_number')
                ->label('Lot number')
                ->state(fn (): ?string => $page->trace['lot']['lot_number'] ?? null)
                ->fontFamily(FontFamily::Mono)
                ->copyable()
                ->placeholder('—'),
            TextEntry::make('lot_expiry_date')
                ->label('Expiry date')
                ->state(fn (): ?string => $page->trace['lot']['expiry_date'] ?? null)
                ->date()
                ->placeholder('—'),
            TextEntry::make('lot_manufacturing_date')
                ->label('Manufacturing date')
                ->state(fn (): ?string => $page->trace['lot']['manufacturing_date'] ?? null)
                ->date()
                ->placeholder('—'),
            TextEntry::make('lot_best_before_date')
                ->label('Best before date')
                ->state(fn (): ?string => $page->trace['lot']['best_before_date'] ?? null)
                ->date()
                ->placeholder('—'),
            TextEntry::make('lot_additional_id')
                ->label('Additional ID')
                ->state(fn (): ?string => $page->trace['lot']['additional_id'] ?? null)
                ->fontFamily(FontFamily::Mono)
                ->placeholder('—'),
        ];
    }

    /**
     * @return list<Component>
     */
    private static function partyEntries(AssetTracking $page): array
    {
        // Labels match BuildAssetTrace::partiesArray() / shippingPartiesSummary().
        $labels = ['Seller', 'Ship-from', 'Sold-to', 'Ship-to'];

        return collect($labels)
            ->map(fn (string $label): TextEntry => TextEntry::make('party_'.str($label)->slug('_'))
                ->label($label)
                ->state(fn (): ?string => $page->trace['parties'][$label] ?? null)
                ->placeholder('—'))
            ->all();
    }
}
