<?php

namespace App\Filament\App\Resources\TransferringSessions\Schemas;

use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Models\Site;
use App\Models\Transferring\TransferringSession;
use App\Support\Gs1\SglnResolution;
use App\Support\TenantSettings;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class TransferringSessionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Session details')
                ->compact()
                ->collapsed()
                ->columns(['md' => 2])
                ->schema([
                    TextEntry::make('fromSite.name')
                        ->label('From site')
                        ->placeholder('—'),
                    TextEntry::make('toSite.name')
                        ->label('To site')
                        ->placeholder('—'),
                    TextEntry::make('fromSite.gln')
                        ->label('From GLN')
                        ->fontFamily(FontFamily::Mono)
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('toSite.gln')
                        ->label('To GLN')
                        ->fontFamily(FontFamily::Mono)
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('from_site_sgln')
                        ->label('From SGLN')
                        ->state(fn (TransferringSession $record): ?string => self::siteSglnUrn($record->fromSite))
                        ->fontFamily(FontFamily::Mono)
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('to_site_sgln')
                        ->label('To SGLN')
                        ->state(fn (TransferringSession $record): ?string => self::siteSglnUrn($record->toSite))
                        ->fontFamily(FontFamily::Mono)
                        ->copyable()
                        ->placeholder('—'),
                    TextEntry::make('opened_at')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('shipped_at')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('received_at')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('completed_at')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('notes')
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('receivingSession.id')
                        ->label('Receive session')
                        ->formatStateUsing(fn (?int $state): string => $state !== null ? '#'.$state : '—')
                        ->url(fn (TransferringSession $record): ?string => $record->receivingSession !== null
                            ? ReceivingSessionResource::getUrl('view', ['record' => $record->receivingSession])
                            : null)
                        ->color('primary')
                        ->placeholder('—')
                        ->visible(fn (TransferringSession $record): bool => $record->receivingSession !== null),
                    TextEntry::make('transferDocument.original_filename')
                        ->label('Transfer EPCIS')
                        ->placeholder(fn (TransferringSession $record): string => filled($record->transferDocument?->document_uuid)
                            ? (string) str($record->transferDocument->document_uuid)->limit(13)
                            : '—')
                        ->url(fn (TransferringSession $record): ?string => $record->transferDocument?->filamentViewUrl())
                        ->color('primary')
                        ->columnSpanFull(),
                    TextEntry::make('transferDocument.document_uuid')
                        ->label('Document UUID')
                        ->limit(13)
                        ->fontFamily(FontFamily::Mono)
                        ->copyable()
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * The SGLN this site would be authored under, or nothing at all. An operator reads
     * this to check what a partner will receive, so showing a value we would refuse to
     * author — a stored URN that parses to another location, or a guessed split — would
     * have them confirm identity we never send. The placeholder says the truth: none.
     */
    private static function siteSglnUrn(?Site $site): ?string
    {
        if ($site === null) {
            return null;
        }

        return SglnResolution::resolve(
            $site->gln,
            [$site->getAttribute('sgln')],
            TenantSettings::forTenant(tenant())->companyPrefix(),
        );
    }
}
