<?php

namespace App\Filament\App\Resources\EpcisJobs\Schemas;

use App\Enums\EpcisJobKind;
use App\Enums\EpcisJobStatus;
use App\Models\EpcisJob;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class EpcisJobInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Status')
                    ->compact()
                    ->columns(['md' => 2])
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (?EpcisJobStatus $state): string => $state?->label() ?? '—')
                            ->color(fn (?EpcisJobStatus $state): string => match ($state) {
                                EpcisJobStatus::Complete => 'success',
                                EpcisJobStatus::Error => 'danger',
                                EpcisJobStatus::Queued, EpcisJobStatus::Sending, EpcisJobStatus::Processing => 'warning',
                                EpcisJobStatus::Cancelled => 'gray',
                                default => 'gray',
                            })
                            ->helperText(fn (EpcisJob $record): ?string => match (true) {
                                $record->status === EpcisJobStatus::Queued && $record->kind === EpcisJobKind::InboundProcess => 'Waiting in the queue to process.',
                                $record->status === EpcisJobStatus::Queued => 'Waiting in the queue to transmit.',
                                $record->status === EpcisJobStatus::Sending => 'Transmission is in progress.',
                                $record->status === EpcisJobStatus::Processing => 'Inbound processing is in progress.',
                                $record->status === EpcisJobStatus::Complete && $record->kind === EpcisJobKind::InboundProcess => 'Inbound processing finished successfully.',
                                $record->status === EpcisJobStatus::Complete => 'Transmission finished successfully.',
                                $record->status === EpcisJobStatus::Error => filled($record->error_message)
                                        ? $record->error_message
                                        : ($record->kind === EpcisJobKind::InboundProcess
                                            ? 'Inbound processing failed.'
                                            : 'Transmission failed.'),
                                $record->status === EpcisJobStatus::Cancelled && $record->kind === EpcisJobKind::InboundProcess => 'Cancelled before processing.',
                                $record->status === EpcisJobStatus::Cancelled => 'Cancelled before send.',
                                default => null,
                            }),
                        TextEntry::make('kind')
                            ->formatStateUsing(fn (?EpcisJobKind $state): string => $state?->label() ?? '—'),
                        TextEntry::make('attempt_count')
                            ->label('Attempts')
                            ->numeric(),
                        TextEntry::make('error_message')
                            ->label('Error')
                            ->placeholder('—')
                            ->columnSpanFull()
                            ->visible(fn (EpcisJob $record): bool => filled($record->error_message)),
                    ]),
                Section::make('Metadata')
                    ->compact()
                    ->columns(['md' => 2])
                    ->schema([
                        TextEntry::make('receipt')
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),
                        TextEntry::make('original_filename')
                            ->label('Filename')
                            ->placeholder('—'),
                        TextEntry::make('requestedByUser.name')
                            ->label('Requested by')
                            ->placeholder('—'),
                        TextEntry::make('outboundConnection.name')
                            ->label('Connection')
                            ->placeholder('—'),
                        TextEntry::make('shipFromSite.name')
                            ->label('Ship-from site')
                            ->placeholder('—'),
                        TextEntry::make('document.asn_number')
                            ->label('ASN')
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('epcis_document_id')
                            ->label('Document')
                            ->formatStateUsing(fn (?int $state): string => $state ? '#'.$state : '—')
                            ->url(fn (EpcisJob $record): ?string => $record->document?->filamentViewUrl())
                            ->color('primary'),
                        TextEntry::make('received_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('started_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('finished_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('processing_time_ms')
                            ->label('Processing time (ms)')
                            ->numeric()
                            ->placeholder('—'),
                    ]),
                Section::make('Stats')
                    ->compact()
                    ->schema([
                        KeyValueEntry::make('stats_json')
                            ->label('')
                            ->placeholder('No stats recorded yet.'),
                    ])
                    ->visible(fn (EpcisJob $record): bool => filled($record->stats_json)),
                Section::make('Messages')
                    ->compact()
                    ->schema([
                        RepeatableEntry::make('messages')
                            ->label('')
                            ->table([
                                TableColumn::make('When'),
                                TableColumn::make('Level'),
                                TableColumn::make('Message'),
                            ])
                            ->schema([
                                TextEntry::make('created_at')
                                    ->dateTime()
                                    ->placeholder('—'),
                                TextEntry::make('level')
                                    ->badge()
                                    ->color(fn (?string $state): string => match ($state) {
                                        'error' => 'danger',
                                        'warning' => 'warning',
                                        'info' => 'info',
                                        default => 'gray',
                                    }),
                                TextEntry::make('message')
                                    ->wrap(),
                            ])
                            ->placeholder('No messages yet.'),
                    ]),
            ]);
    }
}
