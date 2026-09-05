<?php

namespace App\Filament\App\Resources\EpcisDocuments\Schemas;

use App\Enums\EpcisReceivedVia;
use App\Models\Epcis\EpcisDocument;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

class EpcisDocumentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['md' => 2])
            ->components([
                Section::make('Shipment')
                    ->compact()
                    ->schema([
                        View::make('filament.app.epcis.document-summary')
                            ->columnSpanFull(),
                    ]),

                Section::make('Transactions')
                    ->compact()
                    ->columns(['md' => 2])
                    ->schema([
                        TextEntry::make('customer_po')
                            ->label('Purchase Order')
                            ->fontFamily(FontFamily::Mono)
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold)
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('asn_number')
                            ->label('Despatch Advice')
                            ->fontFamily(FontFamily::Mono)
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold)
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('ship_from_name')
                            ->label('Seller')
                            ->placeholder('—')
                            ->formatStateUsing(function (?string $state, EpcisDocument $record): ?string {
                                $seller = $record->shippingPartiesSummary()['seller'];
                                $parts = array_filter([$seller['name'], $seller['gln']]);

                                return $parts === [] ? null : implode(' · ', $parts);
                            }),
                        TextEntry::make('ship_from_site_name')
                            ->label('Ship-from')
                            ->placeholder('—')
                            ->formatStateUsing(function (?string $state, EpcisDocument $record): ?string {
                                $record->loadMissing(['shipFromSite']);
                                $parts = array_filter([
                                    $state ?? $record->shipFromSite?->name,
                                    $record->ship_from_gln,
                                ]);

                                return $parts === [] ? null : implode(' · ', $parts);
                            }),
                        TextEntry::make('ship_to_name')
                            ->label('Sold-to')
                            ->placeholder('—')
                            ->formatStateUsing(function (?string $state, EpcisDocument $record): ?string {
                                $soldTo = $record->shippingPartiesSummary()['sold_to'];
                                $parts = array_filter([$soldTo['name'], $soldTo['gln']]);

                                return $parts === [] ? null : implode(' · ', $parts);
                            }),
                        TextEntry::make('ship_to_site_name')
                            ->label('Ship-to')
                            ->placeholder('—')
                            ->formatStateUsing(function (?string $state, EpcisDocument $record): ?string {
                                $record->loadMissing(['shipToSite']);
                                $parts = array_filter([
                                    $state ?? $record->shipToSite?->name,
                                    $record->ship_to_gln,
                                ]);

                                return $parts === [] ? null : implode(' · ', $parts);
                            }),
                    ]),

                Section::make('DSCSA')
                    ->compact()
                    ->columnSpanFull()
                    ->schema([
                        IconEntry::make('dscsa_affirm')
                            ->label('Transaction statement affirmed')
                            ->boolean(),
                        TextEntry::make('legal_notice')
                            ->label('Legal notice')
                            ->placeholder('—')
                            ->size(TextSize::Small)
                            ->columnSpanFull(),
                    ]),

                Section::make('File details')
                    ->compact()
                    ->collapsed()
                    ->columnSpanFull()
                    ->columns(['md' => 2])
                    ->schema([
                        TextEntry::make('document_uuid')
                            ->label('UUID')
                            ->copyable()
                            ->fontFamily(FontFamily::Mono),
                        TextEntry::make('status')
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
                            }),
                        TextEntry::make('transmission_status')
                            ->label('Transmit status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'sent' => 'success',
                                'failed' => 'danger',
                                'queued', 'sending' => 'warning',
                                'skipped' => 'gray',
                                default => 'gray',
                            })
                            ->placeholder('—')
                            ->visible(fn (EpcisDocument $record): bool => $record->direction === 'outbound'),
                        TextEntry::make('schema_version')
                            ->label('Schema')
                            ->placeholder('—'),
                        TextEntry::make('direction')
                            ->badge()
                            ->formatStateUsing(fn (EpcisDocument $record, mixed $state): string => $record->directionDisplayLabel())
                            ->color('gray'),
                        TextEntry::make('received_via')
                            ->label('Received via')
                            ->badge()
                            ->formatStateUsing(fn (?EpcisReceivedVia $state): string => $state?->label() ?? '—')
                            ->placeholder('—')
                            ->visible(fn (EpcisDocument $record): bool => $record->direction === 'inbound'),
                        TextEntry::make('event_count')
                            ->label(fn (EpcisDocument $record): string => $record->direction === 'outbound'
                                ? 'Events (TI file)'
                                : 'Events')
                            ->helperText(fn (EpcisDocument $record): ?string => $record->direction === 'outbound'
                                ? 'From the partner payload. Shipping docs may project only the shipping ObjectEvent in the live event table — Download EPCIS for full TI (commission/pack/ship).'
                                : null)
                            ->numeric(),
                        TextEntry::make('epc_count')
                            ->label('EPCs')
                            ->numeric(),
                        TextEntry::make('payload_path')
                            ->label(fn (EpcisDocument $record): string => $record->direction === 'outbound'
                                ? 'Partner TI payload path'
                                : 'Payload path')
                            ->helperText(fn (EpcisDocument $record): ?string => $record->direction === 'outbound'
                                ? 'Source of truth for what trading partners receive. Retain for payload_retention_years.'
                                : null)
                            ->fontFamily(FontFamily::Mono)
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('creation_date')
                            ->label('Creation date')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('received_at')
                            ->label('Received')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('sent_at')
                            ->label('Sent')
                            ->dateTime()
                            ->placeholder('—')
                            ->visible(fn (EpcisDocument $record): bool => $record->direction === 'outbound'),
                        TextEntry::make('processed_at')
                            ->label('Processed')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('last_processed_at')
                            ->label('Last processed')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('reprocess_count')
                            ->label('Reprocess count')
                            ->numeric()
                            ->placeholder('0'),
                        TextEntry::make('original_filename')
                            ->label('Filename')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('notes')
                            ->label('Notes')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('error_message')
                            ->label('Error')
                            ->color('danger')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('file_sha256')
                            ->label('SHA-256')
                            ->limit(24)
                            ->copyable()
                            ->fontFamily(FontFamily::Mono)
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('tradingPartner.name')
                            ->label(fn (EpcisDocument $record): string => $record->direction === 'outbound'
                                ? 'Partner (customer)'
                                : 'Partner (seller)')
                            ->placeholder('—'),
                        TextEntry::make('shipToPartner.name')
                            ->label('Ship to partner')
                            ->placeholder('—'),
                        TextEntry::make('shipFromSite.name')
                            ->label('Site (ship from)')
                            ->placeholder('—'),
                        TextEntry::make('shipToSite.name')
                            ->label('Ship to site')
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
