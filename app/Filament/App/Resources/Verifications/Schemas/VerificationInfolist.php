<?php

namespace App\Filament\App\Resources\Verifications\Schemas;

use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Models\Verification;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class VerificationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Verification')
                ->compact()
                ->columns(['md' => 2])
                ->schema([
                    TextEntry::make('status')
                        ->label('Result')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            'verified' => 'Verified',
                            'failed' => 'Failed',
                            'suspect' => 'Suspect',
                            'deferred' => 'Deferred',
                            'unavailable' => 'VRS unavailable',
                            'error' => 'Error',
                            default => filled($state) ? ucfirst((string) $state) : '—',
                        })
                        ->color(fn (?string $state): string => match ($state) {
                            'verified' => 'success',
                            'deferred', 'suspect', 'unavailable' => 'warning',
                            'failed', 'error' => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('verified_at')
                        ->label('Verified at')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('gtin14')
                        ->label('GTIN-14')
                        ->fontFamily(FontFamily::Mono)
                        ->placeholder('—'),
                    TextEntry::make('serial')
                        ->fontFamily(FontFamily::Mono)
                        ->placeholder('—'),
                    TextEntry::make('lot')
                        ->placeholder('—'),
                    TextEntry::make('scanned_barcode')
                        ->label('Scanned barcode')
                        ->fontFamily(FontFamily::Mono)
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('verifiedByUser.name')
                        ->label('Verified by')
                        ->placeholder('—'),
                    TextEntry::make('message')
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('exception.title')
                        ->label('Exception')
                        ->placeholder('—')
                        ->url(fn (Verification $record): ?string => $record->exception_id
                            ? ExceptionResource::getUrl('view', ['record' => $record->exception_id])
                            : null)
                        ->visible(fn (Verification $record): bool => $record->exception_id !== null),
                ]),
            Section::make('Request payload')
                ->compact()
                ->schema([
                    KeyValueEntry::make('request_payload')
                        ->label('')
                        ->placeholder('—'),
                ])
                ->visible(fn (Verification $record): bool => filled($record->request_payload)),
            Section::make('Response payload')
                ->compact()
                ->schema([
                    KeyValueEntry::make('response_payload')
                        ->label('')
                        ->placeholder('—'),
                ])
                ->visible(fn (Verification $record): bool => filled($record->response_payload)),
        ]);
    }
}
