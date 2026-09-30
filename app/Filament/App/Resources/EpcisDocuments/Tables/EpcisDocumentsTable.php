<?php

namespace App\Filament\App\Resources\EpcisDocuments\Tables;

use App\Actions\Epcis\EnrichEpcisDocumentShippingFields;
use App\Actions\Epcis\ReprocessEpcisDocument;
use App\Enums\ExceptionReceiveImpact;
use App\Filament\App\Resources\EpcisDocuments\Actions\StartReceivingAction;
use App\Filament\App\Support\QueueSerializedTrackTraceExport;
use App\Filament\Notifications\Notification;
use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\User;
use App\Services\Dscsa\TransactionReportGenerator;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Copy\OperatorNouns;
use App\Support\Dscsa\DscsaTransactionStatementUi;
use App\Support\Epcis\EpcisDocumentXmlDownload;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class EpcisDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'tradingPartner',
                'shipFromSite',
                'shipToSite',
                'shipToPartner',
                'receivingSession',
            ]))
            ->columns([
                TextColumn::make('creation_date')
                    ->label('Date')
                    ->dateTime()
                    ->sortable()
                    ->columnFilter(ColumnFilter::date()->syncWith('creation_date')),
                TextColumn::make('seller_display')
                    ->label('Seller')
                    ->state(fn (EpcisDocument $r): ?string => $r->shippingPartiesSummary()['seller']['name'])
                    ->placeholder('—')
                    ->limit(28)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $dir = strtolower($direction) === 'desc' ? 'desc' : 'asc';

                        return $query->orderBy('ship_from_name', $dir);
                    })
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('ship_from_name')
                            ->label('Seller')
                            ->options(fn (): array => self::distinctInboundOptions('ship_from_name')),
                    ),
                TextColumn::make('ship_from_display')
                    ->label('Ship-from')
                    ->state(fn (EpcisDocument $r): ?string => $r->ship_from_site_name
                        ?: $r->shipFromSite?->name
                        ?: $r->ship_from_gln)
                    ->placeholder('—')
                    ->limit(28)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->fontFamily(fn (?string $state): ?FontFamily => self::monoIfGlnLike($state))
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $dir = strtolower($direction) === 'desc' ? 'desc' : 'asc';

                        return $query->orderByRaw(
                            'COALESCE(NULLIF(ship_from_site_name, \'\'), ship_from_gln) '.$dir
                        );
                    })
                    ->columnFilter(
                        ColumnFilter::select()
                            ->label('Ship-from')
                            ->options(fn (): array => self::distinctInboundSiteOptions(
                                'ship_from_site_name',
                                'ship_from_gln',
                            ))
                            ->applyUsing(fn (Builder $query, array $data): Builder => self::applySelectedSiteFilter(
                                $query,
                                $data,
                                'ship_from_site_name',
                                'ship_from_gln',
                            )),
                    ),
                TextColumn::make('sold_to_display')
                    ->label('Sold-to')
                    ->state(function (EpcisDocument $r): ?string {
                        $soldTo = $r->shippingPartiesSummary()['sold_to'];

                        return $soldTo['name'] ?: $soldTo['gln'];
                    })
                    ->placeholder('—')
                    ->limit(28)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->fontFamily(fn (?string $state): ?FontFamily => self::monoIfGlnLike($state))
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $dir = strtolower($direction) === 'desc' ? 'desc' : 'asc';

                        return $query->orderBy('ship_to_name', $dir);
                    })
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('ship_to_name')
                            ->label('Sold-to')
                            ->options(fn (): array => self::distinctInboundOptions('ship_to_name')),
                    ),
                TextColumn::make('ship_to_site_display')
                    ->label('Ship-to')
                    ->state(fn (EpcisDocument $r): ?string => $r->ship_to_site_name
                        ?: $r->shipToSite?->name
                        ?: $r->ship_to_gln)
                    ->placeholder('—')
                    ->limit(28)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->fontFamily(fn (?string $state): ?FontFamily => self::monoIfGlnLike($state))
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $dir = strtolower($direction) === 'desc' ? 'desc' : 'asc';

                        return $query->orderByRaw(
                            'COALESCE(NULLIF(ship_to_site_name, \'\'), ship_to_gln) '.$dir
                        );
                    })
                    ->columnFilter(
                        ColumnFilter::select()
                            ->label('Ship-to')
                            ->options(fn (): array => self::distinctInboundSiteOptions(
                                'ship_to_site_name',
                                'ship_to_gln',
                            ))
                            ->applyUsing(fn (Builder $query, array $data): Builder => self::applySelectedSiteFilter(
                                $query,
                                $data,
                                'ship_to_site_name',
                                'ship_to_gln',
                            )),
                    ),
                TextColumn::make('asn_number')
                    ->label('ASN')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->limit(16)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('asn_number')
                            ->options(fn (): array => self::distinctInboundOptions('asn_number')),
                    ),
                TextColumn::make('customer_po')
                    ->label('Customer PO')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->limit(16)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('customer_po')
                            ->options(fn (): array => self::distinctInboundOptions('customer_po')),
                    ),
                TextColumn::make('event_count')
                    ->label('Events')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(ColumnFilter::range()),
                TextColumn::make('epc_count')
                    ->label('EPCs')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(ColumnFilter::range()),
                TextColumn::make('direction')
                    ->badge()
                    ->formatStateUsing(fn (EpcisDocument $record, mixed $state): string => $record->directionDisplayLabel())
                    ->color('gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('schema_version')
                    ->label('Schema')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(ColumnFilter::select()->syncWith('schema_version')),
                TextColumn::make('document_uuid')
                    ->label('UUID')
                    ->limit(12)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('document_uuid')
                            ->options(fn (): array => self::distinctInboundOptions('document_uuid')),
                    ),
                TextColumn::make('original_filename')
                    ->label('Filename')
                    ->limit(28)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('original_filename')
                            ->options(fn (): array => self::distinctInboundOptions('original_filename')),
                    ),
                IconColumn::make('dscsa_affirm')
                    ->label('DSCSA')
                    ->getStateUsing(function (EpcisDocument $record): ?bool {
                        if (! DscsaTransactionStatementUi::applies($record)) {
                            return null;
                        }

                        return (bool) $record->dscsa_affirm;
                    })
                    ->boolean()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('received_at')
                    ->label('Uploaded')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(ColumnFilter::date()->syncWith('received_at')),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(function (EpcisDocument $record, mixed $state): string {
                        return $record->floorReceiveStatusLabel()
                            ?? (filled($state) ? ucfirst((string) $state) : '—');
                    })
                    ->color(function (EpcisDocument $record, mixed $state): string {
                        return $record->floorReceiveStatusColor() ?? match ($state) {
                            'parsed', 'validated' => 'success',
                            'parsing', 'received' => 'warning',
                            'error' => 'danger',
                            'voided' => 'gray',
                            default => 'gray',
                        };
                    })
                    ->sortable()
                    ->columnFilter(ColumnFilter::select()->syncWith('status')),
            ])
            ->defaultSort('creation_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'Floor receive' => [
                            'floor_received' => OperatorNouns::FLOOR_RECEIVED,
                            'floor_partially_received' => OperatorNouns::FLOOR_PARTIALLY_RECEIVED,
                            'floor_receive_blocked' => OperatorNouns::FLOOR_RECEIVE_BLOCKED,
                        ],
                        'Ingest' => [
                            'received' => 'Uploaded',
                            'parsing' => 'Parsing',
                            'parsed' => 'Parsed',
                            'validated' => 'Validated',
                            'error' => 'Error',
                            'voided' => 'Voided',
                        ],
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyStatusFilter($query, $data)),
                SelectFilter::make('schema_version')
                    ->label('Schema version')
                    ->options([
                        '1.2' => 'EPCIS schema 1.2',
                        '1.3' => 'EPCIS schema 1.3',
                        '2.0' => 'EPCIS schema 2.0',
                    ]),
                SelectFilter::make('format')
                    ->label('Format')
                    ->options([
                        'xml' => 'XML',
                        'json' => 'JSON-LD',
                    ]),
                SelectFilter::make('trading_partner_id')
                    ->label('Partner')
                    ->relationship('tradingPartner', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('ship_to_partner_id')
                    ->label('Ship-to partner')
                    ->relationship('shipToPartner', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('ship_from_gln')
                    ->label('Ship-from GLN')
                    ->schema([
                        TextInput::make('value')
                            ->label('Ship-from GLN'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyGlnEqualityFilter(
                        $query,
                        'ship_from_gln',
                        $data['value'] ?? null,
                    )),
                Filter::make('ship_to_gln')
                    ->label('Ship-to GLN')
                    ->schema([
                        TextInput::make('value')
                            ->label('Ship-to GLN'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyGlnEqualityFilter(
                        $query,
                        'ship_to_gln',
                        $data['value'] ?? null,
                    )),
                Filter::make('sender_gln')
                    ->label('Sender GLN')
                    ->schema([
                        TextInput::make('value')
                            ->label('Sender GLN'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyGlnEqualityFilter(
                        $query,
                        'sender_gln',
                        $data['value'] ?? null,
                    )),
                Filter::make('receiver_gln')
                    ->label('Receiver GLN')
                    ->schema([
                        TextInput::make('value')
                            ->label('Receiver GLN'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyGlnEqualityFilter(
                        $query,
                        'receiver_gln',
                        $data['value'] ?? null,
                    )),
                Filter::make('asn_number')
                    ->label('ASN or PO')
                    ->schema([
                        TextInput::make('value')
                            ->label('ASN or PO'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyAsnOrPoFilter(
                        $query,
                        $data['value'] ?? null,
                    )),
                Filter::make('lot_number')
                    ->label('Lot')
                    ->schema([
                        TextInput::make('value')
                            ->label('Lot'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyLotNumberFilter(
                        $query,
                        $data['value'] ?? null,
                    )),
                Filter::make('gtin14')
                    ->label('GTIN')
                    ->schema([
                        TextInput::make('value')
                            ->label('GTIN'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyGtinFilter(
                        $query,
                        $data['value'] ?? null,
                    )),
                Filter::make('customer_po')
                    ->label('Customer PO')
                    ->schema([
                        TextInput::make('value')
                            ->label('Customer PO'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyExactOrPrefixFilter(
                        $query,
                        'customer_po',
                        $data['value'] ?? null,
                    )),
                TernaryFilter::make('dscsa_affirm')
                    ->label('DSCSA TS affirmed'),
                Filter::make('creation_date')
                    ->label('Creation date')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Creation from'),
                        DatePicker::make('until')
                            ->label('Creation until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyDateRangeFilter(
                        $query,
                        'creation_date',
                        $data['from'] ?? null,
                        $data['until'] ?? null,
                    )),
                Filter::make('received_at')
                    ->label('Uploaded')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Uploaded from'),
                        DatePicker::make('until')
                            ->label('Uploaded until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyDateRangeFilter(
                        $query,
                        'received_at',
                        $data['from'] ?? null,
                        $data['until'] ?? null,
                    )),
            ], FiltersLayout::Modal)
            ->filtersFormColumns(3)
            ->filtersFormWidth(Width::FiveExtraLarge)
            ->deferLoading()
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions([
                ViewAction::make()
                    ->iconButton()
                    ->color('secondary')
                    ->tooltip(__('View')),
                StartReceivingAction::forTable()
                    ->iconButton()
                    ->color('secondary'),
                Action::make('trackTrace')
                    ->label('Track & Trace')
                    ->icon(Heroicon::OutlinedMap)
                    ->iconButton()
                    ->color('secondary')
                    ->disabled(fn (EpcisDocument $record): bool => ! in_array($record->status, ['parsed', 'validated'], true))
                    ->tooltip(fn (EpcisDocument $record): ?string => in_array($record->status, ['parsed', 'validated'], true)
                        ? 'Download Transaction Report PDF (one page per lot)'
                        : 'Document must be parsed or validated before generating a Transaction Report')
                    ->action(function (EpcisDocument $record) {
                        /** @var User|null $actor */
                        $actor = auth()->user();
                        $result = app(TransactionReportGenerator::class)->generate($record, $actor);

                        activity()
                            ->performedOn($record)
                            ->causedBy($actor)
                            ->withProperties([
                                'lots' => count($result['data']->pages),
                                'filename' => $result['filename'],
                            ])
                            ->log('Downloaded Transaction Report');

                        return response()->streamDownload(
                            static function () use ($result): void {
                                echo $result['binary'];
                            },
                            $result['filename'],
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),
                Action::make('serializedTrackTrace')
                    ->label('Serialized Track & Trace')
                    ->icon(Heroicon::OutlinedViewfinderCircle)
                    ->iconButton()
                    ->color('secondary')
                    ->disabled(fn (EpcisDocument $record): bool => ! in_array($record->status, ['parsed', 'validated'], true))
                    ->tooltip(fn (EpcisDocument $record): ?string => in_array($record->status, ['parsed', 'validated'], true)
                        ? 'Queue DSCSA Compliance Report PDF (serials by lot). You will be notified when it is ready.'
                        : 'Document must be parsed or validated before generating a Compliance Report')
                    ->action(function (EpcisDocument $record): void {
                        /** @var User|null $actor */
                        $actor = auth()->user();

                        QueueSerializedTrackTraceExport::forDocument($record, $actor);
                    }),
                RecordActionGroup::make([
                    RegulatoryCompliance::apply(
                        Action::make('reprocess')
                            ->label('Re-process')
                            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
                            ->color('warning')
                            ->requiresConfirmation()
                            ->visible(fn (EpcisDocument $record): bool => JobRoleAccess::allowsAny(
                                Permissions::NavExceptions,
                                Permissions::NavIntegrations,
                            )
                                && ! $record->isFloorReceived()
                                && in_array($record->status, ['parsed', 'validated', 'error'], true))
                            ->action(function (EpcisDocument $record): void {
                                $sync = Queue::getDefaultDriver() === 'sync';

                                try {
                                    $document = app(ReprocessEpcisDocument::class)->handle($record, $sync);
                                } catch (Throwable $e) {
                                    Notification::make()
                                        ->title('Re-process failed')
                                        ->body($e->getMessage())
                                        ->danger()
                                        ->send();

                                    return;
                                }

                                Notification::make()
                                    ->title($sync || $document->status === 'parsed' ? 'Re-process complete' : 'Re-process queued')
                                    ->body('Status: '.$document->status.' · Reprocess #'.(int) $document->reprocess_count)
                                    ->success()
                                    ->send();
                            }),
                        'epcis_reprocess',
                        requireReason: false,
                    ),
                    Action::make('refresh')
                        ->label('Refresh')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->visible(fn (): bool => JobRoleAccess::allowsAny(
                            Permissions::NavExceptions,
                            Permissions::NavIntegrations,
                        ))
                        ->action(function (EpcisDocument $record): void {
                            app(EnrichEpcisDocumentShippingFields::class)->handle($record);

                            Notification::make()
                                ->title('Shipping fields refreshed')
                                ->success()
                                ->send();
                        }),
                    Action::make('downloadXml')
                        ->label('Download EPCIS')
                        ->icon(Heroicon::OutlinedArrowDownTray)
                        ->visible(fn (EpcisDocument $record): bool => filled($record->payload_path))
                        ->disabled(fn (EpcisDocument $record): bool => ! EpcisDocumentXmlDownload::available($record))
                        ->tooltip(fn (EpcisDocument $record): ?string => EpcisDocumentXmlDownload::available($record)
                            ? 'Download the stored EPCIS payload'
                            : 'Payload is missing from storage')
                        ->action(function (EpcisDocument $record) {
                            if (! EpcisDocumentXmlDownload::available($record)) {
                                Notification::make()
                                    ->title('Payload file missing')
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
                                    'schema_version' => $record->schema_version,
                                    'format' => $record->format,
                                ])
                                ->log('Downloaded EPCIS payload');

                            return EpcisDocumentXmlDownload::response($record);
                        }),
                ]),
            ]);
    }

    private static function monoIfGlnLike(?string $state): ?FontFamily
    {
        if ($state === null || $state === '') {
            return null;
        }

        return preg_match('/^\d{13}$/', $state) === 1 ? FontFamily::Mono : null;
    }

    /**
     * Status column / modal filter: floor receive badges + ingest pipeline values.
     *
     * @param  Builder<EpcisDocument>  $query
     * @param  array<string, mixed>  $data
     * @return Builder<EpcisDocument>
     */
    private static function applyStatusFilter(Builder $query, array $data): Builder
    {
        $value = $data['value'] ?? null;

        if (! filled($value)) {
            return $query;
        }

        return match ((string) $value) {
            'floor_received' => self::constrainFloorReceived($query),
            'floor_partially_received' => self::constrainFloorPartiallyReceived($query),
            'floor_receive_blocked' => self::constrainFloorReceiveBlocked($query),
            default => $query->where($query->getModel()->getTable().'.status', (string) $value),
        };
    }

    /**
     * Validated inbound files that are not yet fully floor-received.
     *
     * @param  Builder<EpcisDocument>  $query
     * @return Builder<EpcisDocument>
     */
    public static function constrainNotFloorReceived(Builder $query): Builder
    {
        return $query->whereNot(function (Builder $received): void {
            self::constrainFloorReceived($received);
        });
    }

    /**
     * Approximate {@see EpcisDocument::floorReceiveStatusLabel()} === Received.
     *
     * @param  Builder<EpcisDocument>  $query
     * @return Builder<EpcisDocument>
     */
    public static function constrainFloorReceived(Builder $query): Builder
    {
        return $query->where(function (Builder $outer): void {
            $outer
                ->whereHas('inboundShipment', function (Builder $shipment): void {
                    $shipment
                        ->where(function (Builder $activity): void {
                            $activity->where('confirmed_parent_count', '>', 0)
                                ->orWhere('confirmed_each_count', '>', 0)
                                ->orWhereHas('expectedLines', fn (Builder $line): Builder => $line->where('status', 'confirmed'));
                        })
                        ->whereDoesntHave('expectedLines', fn (Builder $line): Builder => $line->where('status', 'expected'));
                })
                ->orWhere(function (Builder $sessionPath): void {
                    $sessionPath
                        ->where(function (Builder $noOpenShipmentRemain): void {
                            $noOpenShipmentRemain
                                ->whereDoesntHave('inboundShipment')
                                ->orWhereHas('inboundShipment', function (Builder $shipment): void {
                                    $shipment->whereDoesntHave(
                                        'expectedLines',
                                        fn (Builder $line): Builder => $line->where('status', 'expected'),
                                    );
                                });
                        })
                        ->whereHas('receivingSession', function (Builder $session): void {
                            $session->where('status', '!=', 'cancelled')
                                ->where(function (Builder $done): void {
                                    $done->where('status', 'completed')
                                        ->orWhere(function (Builder $counts): void {
                                            $counts
                                                ->where(function (Builder $parents): void {
                                                    $parents->where('expected_parent_count', 0)
                                                        ->orWhereColumn('confirmed_parent_count', '>=', 'expected_parent_count');
                                                })
                                                ->where(function (Builder $children): void {
                                                    $children->where('expected_child_count', 0)
                                                        ->orWhereColumn('confirmed_child_count', '>=', 'expected_child_count');
                                                })
                                                ->where(function (Builder $activity): void {
                                                    $activity->where('expected_parent_count', '>', 0)
                                                        ->orWhere('expected_child_count', '>', 0)
                                                        ->orWhere('confirmed_parent_count', '>', 0)
                                                        ->orWhere('confirmed_child_count', '>', 0);
                                                });
                                        });
                                });
                        });
                });
        });
    }

    /**
     * Approximate {@see EpcisDocument::floorReceiveStatusLabel()} === Partially Received.
     *
     * @param  Builder<EpcisDocument>  $query
     * @return Builder<EpcisDocument>
     */
    private static function constrainFloorPartiallyReceived(Builder $query): Builder
    {
        return $query->where(function (Builder $outer): void {
            $outer
                ->whereHas('inboundShipment', function (Builder $shipment): void {
                    $shipment
                        ->where(function (Builder $activity): void {
                            $activity->where('confirmed_parent_count', '>', 0)
                                ->orWhere('confirmed_each_count', '>', 0)
                                ->orWhereHas('expectedLines', fn (Builder $line): Builder => $line->where('status', 'confirmed'))
                                ->orWhereHas('receivingSessions', function (Builder $session): void {
                                    $session->where('status', '!=', 'cancelled')
                                        ->where(function (Builder $active): void {
                                            $active->whereIn('status', ['in_progress', 'completed'])
                                                ->orWhere('confirmed_parent_count', '>', 0)
                                                ->orWhere('confirmed_child_count', '>', 0);
                                        });
                                });
                        })
                        ->whereHas('expectedLines', fn (Builder $line): Builder => $line->where('status', 'expected'));
                })
                ->orWhere(function (Builder $sessionPath): void {
                    $sessionPath
                        ->whereDoesntHave('inboundShipment', function (Builder $shipment): void {
                            $shipment
                                ->where(function (Builder $activity): void {
                                    $activity->where('confirmed_parent_count', '>', 0)
                                        ->orWhere('confirmed_each_count', '>', 0);
                                })
                                ->whereDoesntHave(
                                    'expectedLines',
                                    fn (Builder $line): Builder => $line->where('status', 'expected'),
                                );
                        })
                        ->whereHas('receivingSession', function (Builder $session): void {
                            $session->where('status', '!=', 'cancelled')
                                ->where('status', '!=', 'completed')
                                ->where(function (Builder $partial): void {
                                    $partial->where('status', 'in_progress')
                                        ->orWhere('confirmed_parent_count', '>', 0)
                                        ->orWhere('confirmed_child_count', '>', 0);
                                })
                                ->where(function (Builder $notFullyDone): void {
                                    $notFullyDone
                                        ->where(function (Builder $parents): void {
                                            $parents->where('expected_parent_count', '>', 0)
                                                ->whereColumn('confirmed_parent_count', '<', 'expected_parent_count');
                                        })
                                        ->orWhere(function (Builder $children): void {
                                            $children->where('expected_child_count', '>', 0)
                                                ->whereColumn('confirmed_child_count', '<', 'expected_child_count');
                                        })
                                        ->orWhere(function (Builder $openProgress): void {
                                            $openProgress->where('status', 'in_progress')
                                                ->where('expected_parent_count', 0)
                                                ->where('expected_child_count', 0);
                                        });
                                });
                        });
                });
        });
    }

    /**
     * Approximate {@see EpcisDocument::floorReceiveStatusLabel()} === Receive Blocked.
     *
     * @param  Builder<EpcisDocument>  $query
     * @return Builder<EpcisDocument>
     */
    private static function constrainFloorReceiveBlocked(Builder $query): Builder
    {
        $blockingImpacts = [
            ExceptionReceiveImpact::HardBlocking->value,
            ExceptionReceiveImpact::BusinessRule->value,
        ];

        $documentTable = $query->getModel()->getTable();

        return $query->whereIn($documentTable.'.id', ExceptionCase::query()
            ->open()
            ->whereDoesntHave('epcs')
            ->whereHas('type', function (Builder $type) use ($blockingImpacts): void {
                $type->whereIn('receive_impact', $blockingImpacts);
            })
            ->whereNotNull('document_id')
            ->select('document_id'));
    }

    /**
     * @param  Builder<EpcisDocument>  $query
     * @return Builder<EpcisDocument>
     */
    private static function applyGlnEqualityFilter(Builder $query, string $column, mixed $value): Builder
    {
        if (! filled($value)) {
            return $query;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if (strlen($digits) !== 13) {
            return $query;
        }

        return $query->where($column, $digits);
    }

    /**
     * @param  Builder<EpcisDocument>  $query
     * @return Builder<EpcisDocument>
     */
    private static function applyExactOrPrefixFilter(Builder $query, string $column, mixed $value): Builder
    {
        if (! filled($value)) {
            return $query;
        }

        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($column, $trimmed): void {
            $inner->where($column, $trimmed)
                ->orWhere($column, 'like', $trimmed.'%');
        });
    }

    /**
     * Match DESADV ASN or customer PO (warehouse refs are often labeled "ASN").
     *
     * @param  Builder<EpcisDocument>  $query
     * @return Builder<EpcisDocument>
     */
    private static function applyAsnOrPoFilter(Builder $query, mixed $value): Builder
    {
        if (! filled($value)) {
            return $query;
        }

        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return $query;
        }

        return $query->where(function (Builder $outer) use ($trimmed): void {
            $outer->where(function (Builder $inner) use ($trimmed): void {
                $inner->where('asn_number', $trimmed)
                    ->orWhere('asn_number', 'like', $trimmed.'%');
            })->orWhere(function (Builder $inner) use ($trimmed): void {
                $inner->where('customer_po', $trimmed)
                    ->orWhere('customer_po', 'like', $trimmed.'%');
            });
        });
    }

    /**
     * @param  Builder<EpcisDocument>  $query
     * @return Builder<EpcisDocument>
     */
    private static function applyLotNumberFilter(Builder $query, mixed $value): Builder
    {
        if (! filled($value)) {
            return $query;
        }

        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return $query;
        }

        return $query->whereExists(function (QueryBuilder $exists) use ($trimmed): void {
            self::seedDocumentEpcExists($exists);
            $exists->join('epc_ilmd', 'epc_ilmd.epc_id', '=', 'document_epcs.epc_id')
                ->where(function (QueryBuilder $inner) use ($trimmed): void {
                    $inner->where('epc_ilmd.lot_number', $trimmed)
                        ->orWhere('epc_ilmd.lot_number', 'like', $trimmed.'%');
                });
        });
    }

    /**
     * @param  Builder<EpcisDocument>  $query
     * @return Builder<EpcisDocument>
     */
    private static function applyGtinFilter(Builder $query, mixed $value): Builder
    {
        if (! filled($value)) {
            return $query;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if ($digits === '') {
            return $query;
        }

        return $query->whereExists(function (QueryBuilder $exists) use ($digits): void {
            self::seedDocumentEpcExists($exists);

            if (Schema::hasColumn('epc_ilmd', 'gtin14')) {
                $exists->join('epc_ilmd', 'epc_ilmd.epc_id', '=', 'document_epcs.epc_id')
                    ->where('epc_ilmd.gtin14', $digits);

                return;
            }

            $exists->join('epcs', 'epcs.id', '=', 'document_epcs.epc_id')
                ->where('epcs.gtin14', $digits);
        });
    }

    private static function seedDocumentEpcExists(QueryBuilder $exists): void
    {
        if (Schema::hasTable('document_epcs')) {
            $exists->selectRaw('1')
                ->from('document_epcs')
                ->whereColumn('document_epcs.document_id', 'epcis_documents.id');

            if (Schema::hasColumn('epcis_documents', 'ingest_generation')
                && Schema::hasColumn('document_epcs', 'ingest_generation')) {
                $exists->whereColumn(
                    'document_epcs.ingest_generation',
                    'epcis_documents.ingest_generation',
                );
            }

            return;
        }

        $exists->selectRaw('1')
            ->from('event_epcs as document_epcs')
            ->join('epcis_events', 'epcis_events.id', '=', 'document_epcs.event_id')
            ->whereColumn('epcis_events.document_id', 'epcis_documents.id');

        if (Schema::hasColumn('epcis_events', 'ingest_generation')
            && Schema::hasColumn('epcis_documents', 'ingest_generation')) {
            $exists->whereColumn(
                'epcis_events.ingest_generation',
                'epcis_documents.ingest_generation',
            );
        }
    }

    /**
     * @param  Builder<EpcisDocument>  $query
     * @return Builder<EpcisDocument>
     */
    private static function applyDateRangeFilter(
        Builder $query,
        string $column,
        mixed $from,
        mixed $until,
    ): Builder {
        return $query
            ->when(
                filled($from),
                fn (Builder $query): Builder => $query->whereDate($column, '>=', $from),
            )
            ->when(
                filled($until),
                fn (Builder $query): Builder => $query->whereDate($column, '<=', $until),
            );
    }

    /**
     * @return array<string, string>
     */
    private static function distinctInboundOptions(string $column, int $limit = 250): array
    {
        $values = EpcisDocument::query()
            ->inboundCatalog()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->limit($limit)
            ->pluck($column)
            ->filter(fn (mixed $value): bool => filled($value));

        $options = [];

        foreach ($values as $value) {
            $options[(string) $value] = (string) $value;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function distinctInboundSiteOptions(string $nameColumn, string $glnColumn, int $limit = 250): array
    {
        $rows = EpcisDocument::query()
            ->inboundCatalog()
            ->select([$nameColumn, $glnColumn])
            ->where(function (Builder $query) use ($nameColumn, $glnColumn): void {
                $query->where(function (Builder $named) use ($nameColumn): void {
                    $named->whereNotNull($nameColumn)->where($nameColumn, '!=', '');
                })->orWhere(function (Builder $gln) use ($glnColumn): void {
                    $gln->whereNotNull($glnColumn)->where($glnColumn, '!=', '');
                });
            })
            ->limit($limit)
            ->get();

        $options = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row->getAttribute($nameColumn) ?? ''));
            $gln = trim((string) ($row->getAttribute($glnColumn) ?? ''));
            $value = $gln !== '' ? $gln : $name;
            $label = $name !== '' ? $name : $gln;

            if ($value === '') {
                continue;
            }

            $options[$value] = $label;
        }

        natcasesort($options);

        return $options;
    }

    /**
     * @param  Builder<EpcisDocument>  $query
     * @param  array<string, mixed>  $data
     * @return Builder<EpcisDocument>
     */
    private static function applySelectedSiteFilter(
        Builder $query,
        array $data,
        string $nameColumn,
        string $glnColumn,
    ): Builder {
        $values = array_values(array_filter(
            (array) ($data['values'] ?? $data['value'] ?? []),
            fn (mixed $value): bool => filled($value),
        ));

        if ($values === []) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($values, $nameColumn, $glnColumn): void {
            $inner->whereIn($glnColumn, $values)
                ->orWhereIn($nameColumn, $values);
        });
    }
}
