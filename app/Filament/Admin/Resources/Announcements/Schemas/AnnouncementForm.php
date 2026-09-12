<?php

namespace App\Filament\Admin\Resources\Announcements\Schemas;

use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Support\Filament\ProseEditor;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AnnouncementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Announcement')
                ->compact()
                ->columns(['md' => 2])
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->disabled(fn (?Announcement $record): bool => self::isLocked($record)),
                    ProseEditor::make('body')
                        ->required()
                        ->disabled(fn (?Announcement $record): bool => self::isLocked($record)),
                    Select::make('severity')
                        ->options(collect(AnnouncementSeverity::cases())->mapWithKeys(
                            fn (AnnouncementSeverity $severity): array => [$severity->value => ucfirst($severity->value)]
                        ))
                        ->default(AnnouncementSeverity::Info->value)
                        ->required()
                        ->disabled(fn (?Announcement $record): bool => self::isLocked($record)),
                    Select::make('status')
                        ->options(collect(AnnouncementStatus::cases())->mapWithKeys(
                            fn (AnnouncementStatus $status): array => [$status->value => ucfirst($status->value)]
                        ))
                        ->default(AnnouncementStatus::Draft->value)
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (string $operation): bool => $operation !== 'create'),
                    DateTimePicker::make('starts_at')
                        ->nullable()
                        ->disabled(fn (?Announcement $record): bool => self::isLocked($record)),
                    DateTimePicker::make('ends_at')
                        ->nullable()
                        ->disabled(fn (?Announcement $record): bool => self::isLocked($record)),
                    Select::make('tenants')
                        ->relationship('tenants', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->columnSpanFull()
                        ->disabled(fn (?Announcement $record): bool => $record !== null && $record->status !== AnnouncementStatus::Draft),
                ]),
        ]);
    }

    private static function isLocked(?Announcement $record): bool
    {
        return $record !== null && $record->status !== AnnouncementStatus::Draft;
    }
}
