<?php

namespace App\Filament\App\Resources\LocationDevices\Schemas;

use App\Support\Auth\CurrentSite;
use App\Support\Gs1\GlnRules;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LocationDeviceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Placement')
                ->compact()
                ->columns(['md' => 2, 'lg' => 3])
                ->schema([
                    Select::make('site_id')
                        ->label('Site')
                        ->relationship('site', 'name')
                        ->default(fn (): ?int => CurrentSite::id())
                        ->searchable()
                        ->preload()
                        ->searchDebounce(500)
                        ->required()
                        ->native(false),
                    TextInput::make('name')->required()->maxLength(255),
                    GlnRules::input()->required()->unique(ignoreRecord: true),
                    Textarea::make('description')->rows(2)->columnSpanFull(),
                ]),
            Section::make('Branding')
                ->compact()
                ->collapsed()
                ->schema([
                    TextInput::make('logo')->label('Logo URL')->maxLength(2048),
                ]),
            Section::make('Geo')
                ->compact()
                ->collapsed()
                ->columns(['md' => 2, 'lg' => 3])
                ->schema([
                    TextInput::make('latitude')->numeric(),
                    TextInput::make('longitude')->numeric(),
                    TextInput::make('altitude')->numeric(),
                ]),
        ]);
    }
}
