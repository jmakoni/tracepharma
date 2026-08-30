<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\SerializationLots\Schemas;

use App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource;
use App\Filament\App\Resources\OutboundEpcisDocuments\OutboundEpcisDocumentResource;
use App\Models\L3\SerializationLot;
use App\Models\L3\SerializationLotContainerField;
use App\Support\L3\FormatKeyValueMap;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

class SerializationLotInfolist
{
    /**
     * Per-request memoization of container_type => count, keyed by lot id.
     * Avoids re-querying once per Hierarchy tab entry and never loads `fields` JSON.
     *
     * @var array<int, array<string, int>>
     */
    private static array $hierarchyCountsCache = [];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Lot overview')
                    ->compact()
                    ->columns(['md' => 3])
                    ->schema([
                        TextEntry::make('lot_number')
                            ->label('Lot number')
                            ->fontFamily(FontFamily::Mono)
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold)
                            ->copyable(),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'accepted' => 'success',
                                'failed' => 'danger',
                                default => 'warning',
                            }),
                        TextEntry::make('epcis_document_id')
                            ->label('EPCIS document')
                            ->formatStateUsing(fn (?int $state): string => $state ? '#'.$state : '—')
                            ->url(function (SerializationLot $record): ?string {
                                $document = $record->epcisDocument;
                                if ($document === null) {
                                    return null;
                                }

                                $canOpen = $document->direction === 'outbound'
                                    ? OutboundEpcisDocumentResource::canAccess()
                                    : EpcisDocumentResource::canAccess();

                                return $canOpen ? $document->filamentViewUrl() : null;
                            })
                            ->color(function (SerializationLot $record): string {
                                $document = $record->epcisDocument;
                                if ($document === null) {
                                    return 'gray';
                                }

                                $canOpen = $document->direction === 'outbound'
                                    ? OutboundEpcisDocumentResource::canAccess()
                                    : EpcisDocumentResource::canAccess();

                                return $canOpen ? 'primary' : 'gray';
                            }),
                        TextEntry::make('product_name')
                            ->label('Product name')
                            ->placeholder('—'),
                        TextEntry::make('ndc')
                            ->label('Material (NDC)')
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('unit_gtin14')
                            ->label('Unit GTIN')
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('case_gtin14')
                            ->label('Case GTIN')
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('expire_date')
                            ->label('Expiry date')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('mfg_date')
                            ->label('Manufacture date')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('timezone_offset')
                            ->label('Lot TZ offset')
                            ->placeholder('—'),
                        TextEntry::make('site.name')
                            ->label('Site')
                            ->placeholder('—'),
                        TextEntry::make('line_name')
                            ->label('Line')
                            ->placeholder('—'),
                        TextEntry::make('lot_processed_at')
                            ->label('Processed at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('lot_info_saved_at')
                            ->label('Lot info saved at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('pallet_count')
                            ->label('Pallets')
                            ->numeric(),
                        TextEntry::make('case_count')
                            ->label('Cases')
                            ->numeric(),
                        TextEntry::make('unit_count')
                            ->label('Units')
                            ->numeric(),
                    ]),

                Section::make()
                    ->compact()
                    ->schema([
                        Tabs::make('Lot detail')
                            ->columnSpanFull()
                            ->tabs([
                                Tab::make('Lot Control Data')
                                    ->schema([
                                        KeyValueEntry::make('lot_control_data')
                                            ->label('')
                                            ->keyLabel('Field')
                                            ->valueLabel('Value')
                                            ->state(fn (SerializationLot $record): array => FormatKeyValueMap::withNaPlaceholders($record->lot_control_data))
                                            ->placeholder('No lot control data recorded for this feed.'),
                                    ]),
                                Tab::make('Hierarchy')
                                    ->columns(['md' => 3])
                                    ->schema([
                                        TextEntry::make('hierarchy_pallets')
                                            ->label('Pallets')
                                            ->state(fn (SerializationLot $record): int => self::containerTypeCounts($record)['Pallet'] ?? 0)
                                            ->numeric(),
                                        TextEntry::make('hierarchy_cases')
                                            ->label('Cases')
                                            ->state(fn (SerializationLot $record): int => self::containerTypeCounts($record)['Case'] ?? 0)
                                            ->numeric(),
                                        TextEntry::make('hierarchy_bottles')
                                            ->label('Units / Bottles')
                                            ->state(fn (SerializationLot $record): int => self::containerTypeCounts($record)['Bottle'] ?? 0)
                                            ->numeric(),
                                        TextEntry::make('hierarchy_note')
                                            ->label('')
                                            ->columnSpanFull()
                                            ->color('gray')
                                            ->size(TextSize::Small)
                                            ->state('Counts only, by container type. Full aggregation hierarchy (pallet → case → unit) lives in EPCIS — open the linked document above.'),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    /**
     * @return array<string, int>
     */
    private static function containerTypeCounts(SerializationLot $record): array
    {
        $lotId = (int) $record->getKey();

        if (! array_key_exists($lotId, self::$hierarchyCountsCache)) {
            self::$hierarchyCountsCache[$lotId] = SerializationLotContainerField::query()
                ->where('lot_id', $lotId)
                ->selectRaw('container_type, COUNT(*) as total')
                ->groupBy('container_type')
                ->pluck('total', 'container_type')
                ->map(fn (mixed $total): int => (int) $total)
                ->all();
        }

        return self::$hierarchyCountsCache[$lotId];
    }
}
