<?php

namespace App\Filament\Admin\Resources\Fda\FdaProducts\RelationManagers;

use App\Filament\Support\RecordActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IngredientsRelationManager extends RelationManager
{
    protected static string $relationship = 'activeIngredients';

    protected static ?string $title = 'Ingredients';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('strength')->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->wrap(),
                TextColumn::make('strength'),
            ])
            ->defaultSort('id')
            ->recordActions(RecordActionGroup::make([
                EditAction::make(),
            ]));
    }
}
