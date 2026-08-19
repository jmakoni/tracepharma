<?php

namespace App\Filament\Support;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class PartnerContactTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('telephone')
                    ->label('Telephone')
                    ->placeholder('—')
                    ->url(fn (Model $record): ?string => filled($record->getAttribute('telephone'))
                        ? 'tel:'.preg_replace('/[^\d+]/', '', (string) $record->getAttribute('telephone'))
                        : null),
                TextColumn::make('email')
                    ->label('Email')
                    ->placeholder('—')
                    ->copyable()
                    ->url(fn (Model $record): ?string => filled($record->getAttribute('email'))
                        ? 'mailto:'.$record->getAttribute('email')
                        : null),
                TextColumn::make('vrs_notify_email')
                    ->label('VRS notify email')
                    ->placeholder('—')
                    ->copyable()
                    ->visible(fn (Model $record): bool => filled($record->getAttribute('vrs_notify_email')))
                    ->url(fn (Model $record): ?string => filled($record->getAttribute('vrs_notify_email'))
                        ? 'mailto:'.$record->getAttribute('vrs_notify_email')
                        : null),
                TextColumn::make('website')
                    ->label('Website')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): ?string => self::websiteLabel($state))
                    ->url(fn (Model $record): ?string => filled($record->getAttribute('website'))
                        ? (string) $record->getAttribute('website')
                        : null)
                    ->openUrlInNewTab(),
                TextColumn::make('fax')
                    ->label('Fax')
                    ->placeholder('—'),
            ])
            ->paginated(false)
            ->searchable(false)
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    private static function websiteLabel(?string $website): ?string
    {
        if (blank($website)) {
            return null;
        }

        $label = preg_replace('#^https?://#i', '', $website) ?? $website;
        $label = preg_replace('#^www\.#i', '', $label) ?? $label;
        $label = rtrim($label, '/');

        if (strlen($label) > 36) {
            return substr($label, 0, 33).'…';
        }

        return $label;
    }
}
