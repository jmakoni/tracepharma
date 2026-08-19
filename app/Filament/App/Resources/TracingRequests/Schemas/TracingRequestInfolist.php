<?php

namespace App\Filament\App\Resources\TracingRequests\Schemas;

use App\Enums\TracingRequestNotificationStatus;
use App\Enums\TracingRequestorType;
use App\Enums\TracingRequestScope;
use App\Enums\TracingRequestStatus;
use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Filament\App\Resources\TracingRequests\Actions\RecallBroadcastAckLinkActions;
use App\Models\TracingRequest;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TracingRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request')
                    ->schema([
                        TextEntry::make('title'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (TracingRequestStatus $state): string => $state->label())
                            ->color(fn (TracingRequest $record): string => match (true) {
                                $record->status === TracingRequestStatus::Cancelled => 'gray',
                                $record->isOverdue() || $record->sla_breached => 'danger',
                                $record->status === TracingRequestStatus::Completed => 'success',
                                $record->status === TracingRequestStatus::InProgress => 'warning',
                                default => 'info',
                            }),
                        TextEntry::make('requestor_type')
                            ->label('Requestor')
                            ->badge()
                            ->formatStateUsing(fn (TracingRequestorType $state): string => $state->label()),
                        TextEntry::make('scope')
                            ->badge()
                            ->formatStateUsing(fn (TracingRequestScope $state): string => $state->label()),
                        TextEntry::make('gtin')
                            ->label('GTIN')
                            ->placeholder('—'),
                        TextEntry::make('serial')
                            ->placeholder('—'),
                        TextEntry::make('lot')
                            ->placeholder('—'),
                        TextEntry::make('expiry')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('exceptionCase.title')
                            ->label('Linked exception')
                            ->placeholder('—')
                            ->url(fn (TracingRequest $record): ?string => $record->exception_id
                                ? ExceptionResource::getUrl('view', ['record' => $record->exception_id])
                                : null),
                        IconEntry::make('is_recall')
                            ->label('Recall')
                            ->boolean(),
                        TextEntry::make('requestedByUser.name')
                            ->label('Opened by')
                            ->placeholder('—'),
                        TextEntry::make('requested_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('due_at')
                            ->label('SLA due')
                            ->dateTime()
                            ->placeholder('—')
                            ->color(fn (TracingRequest $record): string => $record->isOverdue() || $record->sla_breached ? 'danger' : 'gray'),
                        TextEntry::make('responded_at')
                            ->dateTime()
                            ->placeholder('Not responded'),
                        IconEntry::make('sla_breached')
                            ->label('SLA breached')
                            ->boolean(),
                        TextEntry::make('completed_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('notes')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Recall broadcast')
                    ->schema([
                        RepeatableEntry::make('notifications')
                            ->label('')
                            ->table([
                                TableColumn::make('Partner'),
                                TableColumn::make('Status'),
                                TableColumn::make('Sent'),
                                TableColumn::make('Acknowledged'),
                                TableColumn::make('Link'),
                            ])
                            ->schema([
                                TextEntry::make('tradingPartner.name')
                                    ->label('Partner')
                                    ->placeholder('—'),
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (TracingRequestNotificationStatus $state): string => $state->label())
                                    ->color(fn (TracingRequestNotificationStatus $state): string => match ($state) {
                                        TracingRequestNotificationStatus::Sent => 'success',
                                        TracingRequestNotificationStatus::Failed => 'danger',
                                        TracingRequestNotificationStatus::Acknowledged => 'info',
                                        default => 'gray',
                                    }),
                                TextEntry::make('sent_at')
                                    ->dateTime()
                                    ->placeholder('—'),
                                TextEntry::make('acknowledged_at')
                                    ->dateTime()
                                    ->placeholder('—'),
                                TextEntry::make('ack_link_actions')
                                    ->label('Link')
                                    ->state('')
                                    ->afterContent([
                                        RecallBroadcastAckLinkActions::rotateAckLinkAction(),
                                        RecallBroadcastAckLinkActions::revokeAckLinkAction(),
                                    ]),
                            ])
                            ->placeholder('No recall notices sent yet.'),
                    ])
                    ->visible(fn (TracingRequest $record): bool => (bool) $record->is_recall),
                Section::make('Response')
                    ->schema([
                        TextEntry::make('response_metadata.summary')
                            ->label('Summary')
                            ->placeholder('No response recorded yet')
                            ->columnSpanFull(),
                        TextEntry::make('response_metadata.evidence_reference')
                            ->label('Evidence reference')
                            ->placeholder('—'),
                        TextEntry::make('response_metadata.evidence_notes')
                            ->label('Evidence notes')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->visible(fn (TracingRequest $record): bool => $record->responded_at !== null
                        || filled(data_get($record->response_metadata, 'summary'))),
            ]);
    }
}
