<?php

namespace App\Filament\App\Resources\ReceivingSessions\Schemas;

use App\Enums\ReceivingSessionKind;
use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use App\Models\Receiving\ReceivingSession;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class ReceivingSessionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Session details')
                ->compact()
                ->collapsed()
                ->columns(['md' => 2])
                ->schema([
                    TextEntry::make('session_kind')
                        ->label('Kind')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => $state?->badgeLabel()
                            ?? ReceivingSessionKind::InboundAsn->badgeLabel()),
                    TextEntry::make('tradingPartner.name')
                        ->label('Partner')
                        ->placeholder('—'),
                    TextEntry::make('site.name')
                        ->label('Site')
                        ->placeholder('—'),
                    TextEntry::make('document.original_filename')
                        ->label('Document')
                        ->placeholder(fn (ReceivingSession $record): string => filled($record->document?->document_uuid)
                            ? (string) str($record->document->document_uuid)->limit(13)
                            : '—')
                        ->url(fn (ReceivingSession $record): ?string => $record->document?->filamentViewUrl())
                        ->color('primary')
                        ->visible(fn (ReceivingSession $record): bool => $record->document !== null)
                        ->columnSpanFull(),
                    TextEntry::make('transferring_session_id')
                        ->label('Transfer')
                        ->formatStateUsing(fn ($state): string => $state !== null ? 'Transfer #'.$state : '—')
                        ->url(fn (ReceivingSession $record): ?string => $record->transferring_session_id !== null
                            ? TransferringSessionResource::getUrl('view', ['record' => $record->transferring_session_id])
                            : null)
                        ->color('primary')
                        ->visible(fn (ReceivingSession $record): bool => $record->transferring_session_id !== null),
                    TextEntry::make('opened_at')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('completed_at')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('document.document_uuid')
                        ->label('Document UUID')
                        ->limit(13)
                        ->fontFamily(FontFamily::Mono)
                        ->copyable()
                        ->placeholder('—')
                        ->visible(fn (ReceivingSession $record): bool => $record->document !== null)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
