<?php

namespace App\Filament\App\Resources\EpcisDocuments\RelationManagers;

use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Support\Gs1\EpcBarcodeDisplay;
use App\Support\Tracing\AssetTrackingUrl;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EpcsRelationManager extends RelationManager
{
    /**
     * Dummy relationship for Filament RelationManager contract.
     * The table uses a custom EPC query scoped to this document.
     */
    protected static string $relationship = 'events';

    protected static ?string $title = 'EPCs';

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
        return number_format($ownerRecord->epcsExpandedCount());
    }

    public function table(Table $table): Table
    {
        /** @var EpcisDocument $document */
        $document = $this->getOwnerRecord();

        $parentByChildSubquery = DB::table('aggregation_links as al')
            ->join('epcs as parent_epc', 'parent_epc.id', '=', 'al.parent_epc_id')
            ->select([
                'al.child_epc_id',
                DB::raw('COALESCE(parent_epc.sscc18, parent_epc.epc_uri) as document_parent_label'),
            ])
            ->whereNull('al.valid_to')
            ->whereIn('al.parent_epc_id', $document->baseEpcIdsQuery());

        return $table
            ->query(fn () => $document->epcsQueryExpanded()
                ->leftJoinSub($parentByChildSubquery, 'doc_agg_parent', 'doc_agg_parent.child_epc_id', '=', 'epcs.id')
                ->select('epcs.*', 'doc_agg_parent.document_parent_label')
                ->with('ilmd'))
            ->columns([
                AssetTrackingUrl::linkEpcColumn(
                    TextColumn::make('epc_uri')
                        ->label('EPC URI')
                        ->limit(40)
                        ->tooltip(fn (?string $state): ?string => $state)
                        ->copyable()
                        ->fontFamily(FontFamily::Mono)
                        ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('epcs.epc_uri', 'like', $search.'%')),
                    fn (mixed $record): ?Epc => $record instanceof Epc ? $record : null,
                ),
                TextColumn::make('epc_type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                AssetTrackingUrl::linkEpcColumn(
                    TextColumn::make('gtin14')
                        ->label('GTIN')
                        ->fontFamily(FontFamily::Mono)
                        ->placeholder('—')
                        ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('epcs.gtin14', $search))
                        ->toggleable(),
                    fn (mixed $record): ?Epc => $record instanceof Epc ? $record : null,
                    copyable: true,
                ),
                AssetTrackingUrl::linkEpcColumn(
                    TextColumn::make('sscc18')
                        ->label('SSCC')
                        ->fontFamily(FontFamily::Mono)
                        ->placeholder('—')
                        ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('epcs.sscc18', $search))
                        ->toggleable(),
                    fn (mixed $record): ?Epc => $record instanceof Epc ? $record : null,
                    copyable: true,
                ),
                AssetTrackingUrl::linkScanColumn(
                    TextColumn::make('document_parent_label')
                        ->label('Container')
                        ->fontFamily(FontFamily::Mono)
                        ->placeholder('—')
                        ->tooltip(fn (?string $state): ?string => $state)
                        ->toggleable(),
                    function (mixed $record): ?string {
                        if (! $record instanceof Epc) {
                            return null;
                        }

                        $label = $record->getAttribute('document_parent_label');
                        if (! filled($label)) {
                            return null;
                        }

                        $label = (string) $label;

                        return preg_match('/^\d{18}$/', $label) === 1 ? '(00)'.$label : $label;
                    },
                    copyable: true,
                ),
                AssetTrackingUrl::linkEpcColumn(
                    TextColumn::make('ai_01_21')
                        ->label('AI 01+21')
                        ->limit(64)
                        ->state(function (Epc $record): ?string {
                            if ($record->epc_type !== 'sgtin') {
                                return filled($record->ai_01_21) ? (string) $record->ai_01_21 : null;
                            }

                            $label = EpcBarcodeDisplay::forEpc($record);

                            return filled($label) ? $label : null;
                        })
                        ->tooltip(fn (?string $state): ?string => $state)
                        ->fontFamily(FontFamily::Mono)
                        ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('epcs.ai_01_21', 'like', $search.'%'))
                        ->toggleable(isToggledHiddenByDefault: true),
                    fn (mixed $record): ?Epc => $record instanceof Epc ? $record : null,
                    copyable: true,
                ),
                TextColumn::make('ilmd.lot_number')
                    ->label('Lot')
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('ilmd.expiry_date')
                    ->label('Expiry')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('id')
            ->filters([
                SelectFilter::make('epc_type')
                    ->label('Type')
                    ->options([
                        'sgtin' => 'SGTIN',
                        'sscc' => 'SSCC',
                    ]),
                Filter::make('lot_number')
                    ->schema([
                        TextInput::make('value')
                            ->label('Lot number'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $lot = trim((string) ($data['value'] ?? ''));

                        if ($lot === '') {
                            return $query;
                        }

                        return $query->whereHas(
                            'ilmd',
                            fn (Builder $ilmd): Builder => $ilmd->where('lot_number', $lot),
                        );
                    }),
                Filter::make('expiry_date')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Expiry from'),
                        DatePicker::make('until')
                            ->label('Expiry until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;

                        if (blank($from) && blank($until)) {
                            return $query;
                        }

                        return $query->whereHas('ilmd', function (Builder $ilmd) use ($from, $until): void {
                            if (filled($from)) {
                                $ilmd->whereDate('expiry_date', '>=', $from);
                            }

                            if (filled($until)) {
                                $ilmd->whereDate('expiry_date', '<=', $until);
                            }
                        });
                    }),
                Filter::make('gtin14')
                    ->schema([
                        TextInput::make('value')
                            ->label('GTIN'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $gtin = preg_replace('/\D+/', '', (string) ($data['value'] ?? '')) ?? '';

                        if ($gtin === '') {
                            return $query;
                        }

                        if (strlen($gtin) < 14) {
                            $gtin = str_pad($gtin, 14, '0', STR_PAD_LEFT);
                        }

                        return $query->where('epcs.gtin14', $gtin);
                    }),
            ], FiltersLayout::Modal)
            ->filtersFormColumns(3)
            ->filtersFormWidth(Width::FourExtraLarge)
            ->deferLoading()
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->headerActions([])
            ->recordActions([])
            ->modelLabel('EPC')
            ->pluralModelLabel('EPCs');
    }
}
