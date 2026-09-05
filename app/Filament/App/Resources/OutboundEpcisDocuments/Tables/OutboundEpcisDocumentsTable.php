<?php

namespace App\Filament\App\Resources\OutboundEpcisDocuments\Tables;

use App\Enums\EpcisAuthoredKind;
use App\Filament\App\Resources\OutboundEpcisDocuments\Actions\RetryOutboundEpcisTransmitAction;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Filament\App\Resources\SsccLabels\SsccLabelResource;
use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use App\Filament\Support\RecordActionGroup;
use App\Models\Epcis\EpcisDocument;
use App\Models\User;
use App\Support\Epcis\EpcisDocumentXmlDownload;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use App\Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OutboundEpcisDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'tradingPartner',
                'shipFromSite',
                'shipToSite',
                'shipToPartner',
                'outboundShippingSession',
                'transferringSession',
            ]))
            ->columns([
                TextColumn::make('creation_date')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('direction')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (EpcisDocument $record, mixed $state): string => $record->directionDisplayLabel())
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('ship_from_display')
                    ->label('Ship-from')
                    ->state(fn (EpcisDocument $r): ?string => $r->ship_from_site_name
                        ?: $r->shipFromSite?->name
                        ?: $r->ship_from_gln)
                    ->placeholder('—')
                    ->limit(28)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('sold_to_display')
                    ->label('Sold-to')
                    ->state(fn (EpcisDocument $r): ?string => $r->ship_to_name
                        ?: $r->shipToPartner?->name
                        ?: $r->tradingPartner?->name
                        ?: $r->ship_to_gln)
                    ->placeholder('—')
                    ->limit(28)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('asn_number')
                    ->label('ASN')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->copyable()
                    ->limit(16)
                    ->placeholder('—'),
                TextColumn::make('customer_po')
                    ->label('Customer PO')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->copyable()
                    ->limit(16)
                    ->placeholder('—'),
                TextColumn::make('transmission_status')
                    ->label('Transmit')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        'queued', 'sending' => 'warning',
                        'skipped' => 'gray',
                        default => 'gray',
                    })
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'parsed', 'validated', 'generated' => 'success',
                        'parsing', 'received' => 'warning',
                        'error' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('original_filename')
                    ->label('Filename')
                    ->limit(28)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('dscsa_affirm')
                    ->label('DSCSA')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('event_count')
                    ->label('Events (file)')
                    ->tooltip('Count stored from the partner TI payload (commission/pack/ship). Live DB events for shipping docs may be the shipping ObjectEvent only — use Download EPCIS for the full TI file.')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('epc_count')
                    ->label('EPCs')
                    ->tooltip('EPC membership on the authored shipping document projection.')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('creation_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'generated' => 'Generated',
                        'parsed' => 'Parsed',
                        'validated' => 'Validated',
                        'error' => 'Error',
                    ]),
                SelectFilter::make('transmission_status')
                    ->label('Transmit')
                    ->options([
                        'sent' => 'Sent',
                        'failed' => 'Failed',
                        'queued' => 'Queued',
                        'skipped' => 'Skipped',
                    ]),
                SelectFilter::make('trading_partner_id')
                    ->label('Partner')
                    ->relationship('tradingPartner', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('authored_kind')
                    ->label('Type')
                    ->options(collect(EpcisAuthoredKind::cases())
                        ->mapWithKeys(fn (EpcisAuthoredKind $case): array => [$case->value => $case->filterLabel()])
                        ->all()),
                Filter::make('asn_number')
                    ->schema([
                        TextInput::make('value')->label('ASN'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = trim((string) ($data['value'] ?? ''));
                        if ($value === '') {
                            return $query;
                        }

                        return $query->where('asn_number', 'like', $value.'%');
                    }),
                Filter::make('creation_date')
                    ->label('Creation date')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (filled($data['from'] ?? null)) {
                            $query->whereDate('creation_date', '>=', $data['from']);
                        }
                        if (filled($data['until'] ?? null)) {
                            $query->whereDate('creation_date', '<=', $data['until']);
                        }

                        return $query;
                    }),
            ], FiltersLayout::Modal)
            ->filtersFormColumns(2)
            ->filtersFormWidth(Width::FourExtraLarge)
            ->deferLoading()
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordUrl(fn (EpcisDocument $record): string => $record->filamentViewUrl())
            ->recordActions(RecordActionGroup::make([
                ViewAction::make(),
                Action::make('viewShipOrder')
                    ->label('Ship Order')
                    ->icon(Heroicon::OutlinedTruck)
                    ->url(fn (EpcisDocument $record): ?string => $record->outboundShippingSession
                        ? OutboundShippingSessionResource::getUrl('view', ['record' => $record->outboundShippingSession])
                        : null)
                    ->visible(fn (EpcisDocument $record): bool => $record->outboundShippingSession !== null),
                Action::make('viewTransfer')
                    ->label('Transfer')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->url(fn (EpcisDocument $record): ?string => $record->transferringSession
                        ? TransferringSessionResource::getUrl('view', ['record' => $record->transferringSession])
                        : null)
                    ->visible(fn (EpcisDocument $record): bool => $record->transferringSession !== null),
                Action::make('viewSsccBatch')
                    ->label('SSCC batch')
                    ->icon(Heroicon::OutlinedQrCode)
                    ->url(function (EpcisDocument $record): ?string {
                        $batch = $record->ssccLabelBatch();

                        return $batch !== null
                            ? SsccLabelResource::getUrl('view-batch', ['record' => $batch])
                            : null;
                    })
                    ->visible(fn (EpcisDocument $record): bool => $record->isSsccAuthoredKind())
                    ->disabled(fn (EpcisDocument $record): bool => $record->ssccLabelBatch() === null),
                RetryOutboundEpcisTransmitAction::forTable(),
                Action::make('downloadXml')
                    ->label('Download EPCIS')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (EpcisDocument $record): bool => filled($record->payload_path))
                    ->disabled(fn (EpcisDocument $record): bool => ! EpcisDocumentXmlDownload::available($record))
                    ->action(function (EpcisDocument $record) {
                        if (! EpcisDocumentXmlDownload::available($record)) {
                            Notification::make()
                                ->title('XML file missing')
                                ->body('The payload path is recorded but the file is not on disk.')
                                ->danger()
                                ->send();

                            return null;
                        }

                        /** @var User|null $actor */
                        $actor = auth()->user();

                        activity()
                            ->performedOn($record)
                            ->causedBy($actor)
                            ->withProperties([
                                'filename' => EpcisDocumentXmlDownload::filename($record),
                                'payload_path' => $record->payload_path,
                            ])
                            ->log('Downloaded EPCIS XML');

                        return EpcisDocumentXmlDownload::response($record);
                    }),
            ]));
    }
}
