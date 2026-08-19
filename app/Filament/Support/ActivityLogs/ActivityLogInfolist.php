<?php

namespace App\Filament\Support\ActivityLogs;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class ActivityLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Activity')
                ->compact()
                ->columns(['md' => 2])
                ->schema([
                    TextEntry::make('created_at')
                        ->label('When')
                        ->dateTime(),
                    TextEntry::make('event')
                        ->badge()
                        ->placeholder('—'),
                    TextEntry::make('log_name')
                        ->label('Log')
                        ->badge()
                        ->color('gray')
                        ->placeholder('—'),
                    TextEntry::make('description')
                        ->columnSpanFull(),
                    TextEntry::make('subject_type')
                        ->label('Subject')
                        ->formatStateUsing(fn (?string $state, Model $record): string => self::formatMorph(
                            $state,
                            $record->getAttribute('subject_id'),
                            $record->subject,
                        )),
                    TextEntry::make('causer_type')
                        ->label('Causer')
                        ->formatStateUsing(fn (?string $state, Model $record): string => self::formatCauser($record)),
                ]),
            Section::make('Attribute changes')
                ->compact()
                ->schema([
                    TextEntry::make('attribute_changes')
                        ->hiddenLabel()
                        ->fontFamily(FontFamily::Mono)
                        ->formatStateUsing(fn (mixed $state): string => self::formatJson($state))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->visible(fn (Model $record): bool => filled($record->getAttribute('attribute_changes'))),
            Section::make('Properties')
                ->compact()
                ->schema([
                    TextEntry::make('properties')
                        ->hiddenLabel()
                        ->fontFamily(FontFamily::Mono)
                        ->formatStateUsing(fn (mixed $state): string => self::formatJson($state))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->visible(fn (Model $record): bool => filled($record->getAttribute('properties'))),
        ]);
    }

    private static function formatCauser(Model $record): string
    {
        $causer = $record->causer;
        if ($causer !== null && filled($causer->getAttribute('email'))) {
            return (string) $causer->getAttribute('email');
        }
        if ($causer !== null && filled($causer->getAttribute('name'))) {
            return (string) $causer->getAttribute('name');
        }

        return self::formatMorph(
            $record->getAttribute('causer_type'),
            $record->getAttribute('causer_id'),
            $causer,
        );
    }

    private static function formatMorph(?string $type, mixed $id, ?Model $related = null): string
    {
        if ($related !== null && filled($related->getAttribute('name'))) {
            $label = (string) $related->getAttribute('name');
            $base = $type ? class_basename($type) : class_basename($related);

            return filled($id) ? "{$base}: {$label} (#{$id})" : "{$base}: {$label}";
        }

        if (blank($type)) {
            return '—';
        }

        $base = class_basename($type);

        return filled($id) ? "{$base} #{$id}" : $base;
    }

    private static function formatJson(mixed $state): string
    {
        if ($state === null || $state === '' || (is_countable($state) && count($state) === 0)) {
            return '—';
        }

        if ($state instanceof Collection) {
            $state = $state->toArray();
        }

        if (is_string($state)) {
            return $state;
        }

        return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '—';
    }
}
