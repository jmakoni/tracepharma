<?php

namespace App\Filament\App\Resources\EpcisDocuments\Tables;

use App\Actions\Epcis\EnrichEpcisDocumentShippingFields;
use App\Actions\Epcis\ReprocessEpcisDocument;
use App\Filament\App\Resources\EpcisDocuments\Actions\StartReceivingAction;
use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Epcis\EpcisDocument;
use App\Models\User;
use App\Services\Dscsa\DscsaComplianceReportGenerator;
use App\Services\Dscsa\TransactionReportGenerator;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
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
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

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
                    ->sortable(),
                TextColumn::make('seller_display')
                    ->label('Seller')
                    ->state(fn (EpcisDocument $r): ?string => $r->shippingPartiesSummary()['seller']['name'])
                    ->placeholder('—')
                    ->limit(28)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('ship_from_display')
                    ->label('Ship-from')
                    ->state(fn (EpcisDocument $r): ?string => $r->ship_from_site_name
                        ?: $r->shipFromSite?->name
                        ?: $r->ship_from_gln)
                    ->placeholder('—')
                    ->limit(28)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->fontFamily(fn (?string $state): ?FontFamily => self::monoIfGlnLike($state)),
                TextColumn::make('sold_to_display')
                    ->label('Sold-to')
                    ->state(function (EpcisDocument $r): ?string {
                        $soldTo = $r->shippingPartiesSummary()['sold_to'];

                        return $soldTo['name'] ?: $soldTo['gln'];
                    })
                    ->placeholder('—')
                    ->limit(28)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->fontFamily(fn (?string $state): ?FontFamily => self::monoIfGlnLike($state)),
                TextColumn::make('ship_to_site_display')
                    ->label('Ship-to')
                    ->state(fn (EpcisDocument $r): ?string => $r->ship_to_site_name
                        ?: $r->shipToSite?->name
                        ?: $r->ship_to_gln)
                    ->placeholder('—')
                    ->limit(28)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->fontFamily(fn (?string $state): ?FontFamily => self::monoIfGlnLike($state)),
                TextColumn::make('asn_number')
                    ->label('ASN')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->copyable()
                    ->limit(16)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—'),
                TextColumn::make('customer_po')
                    ->label('Customer PO')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->copyable()
                    ->limit(16)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—'),
                TextColumn::make('event_count')
                    ->label('Events')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('epc_count')
                    ->label('EPCs')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('document_uuid')
                    ->label('UUID')
                    ->limit(12)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                TextColumn::make('received_at')
                    ->label('Received')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
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
                    // Pin Status just left of sticky Actions (~icon-button width); z-index above scrolling cells.
                    ->stickyRight(offset: 56, zIndex: 11),
            ])
            ->defaultSort('creation_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'received' => 'Received',
                        'parsing' => 'Parsing',
                        'parsed' => 'Parsed',
                        'validated' => 'Validated',
                        'error' => 'Error',
                        'voided' => 'Voided',
                    ]),
                SelectFilter::make('schema_version')
                    ->label('Schema version')
                    ->options([
                        '1.2' => 'EPCIS 1.2',
                        '1.3' => 'EPCIS 1.3',
                        '2.0' => 'EPCIS 2.0',
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
                    ->label('Received')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Received from'),
                        DatePicker::make('until')
                            ->label('Received until'),
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
            ->extraAttributes(['class' => 'tp-inbound-epcis-table'])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                ViewAction::make(),
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
                StartReceivingAction::forTable(),
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
                Action::make('trackTrace')
                    ->label('Track & Trace')
                    ->icon(Heroicon::OutlinedMap)
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
                    ->disabled(fn (EpcisDocument $record): bool => ! in_array($record->status, ['parsed', 'validated'], true))
                    ->tooltip(fn (EpcisDocument $record): ?string => in_array($record->status, ['parsed', 'validated'], true)
                        ? 'Download DSCSA Compliance Report PDF (serials by lot)'
                        : 'Document must be parsed or validated before generating a Compliance Report')
                    ->action(function (EpcisDocument $record) {
                        /** @var User|null $actor */
                        $actor = auth()->user();
                        $result = app(DscsaComplianceReportGenerator::class)->generate($record, $actor);

                        activity()
                            ->performedOn($record)
                            ->causedBy($actor)
                            ->withProperties([
                                'lots' => count($result['data']->lots),
                                'serials' => $result['data']->serialCount,
                                'filename' => $result['filename'],
                            ])
                            ->log('Downloaded DSCSA Compliance Report');

                        return response()->streamDownload(
                            static function () use ($result): void {
                                echo $result['binary'];
                            },
                            $result['filename'],
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),
            ]));
    }

    private static function monoIfGlnLike(?string $state): ?FontFamily
    {
        if ($state === null || $state === '') {
            return null;
        }

        return preg_match('/^\d{13}$/', $state) === 1 ? FontFamily::Mono : null;
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
}
