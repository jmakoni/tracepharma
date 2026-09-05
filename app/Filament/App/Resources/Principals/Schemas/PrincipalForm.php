<?php

namespace App\Filament\App\Resources\Principals\Schemas;

use App\Support\Gs1\GlnRules;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PrincipalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Principal')
                    ->compact()
                    ->columns(['md' => 2])
                    ->description('Soft label for 3PL client tagging on sites and ship orders — not EPC custody isolation.')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        GlnRules::input()
                            ->nullable()
                            ->helperText('Optional principal GLN when known.'),
                        Toggle::make('is_active')->default(true),
                    ]),
            ]);
    }
}
