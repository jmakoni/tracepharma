<?php

namespace App\Filament\Admin\Resources\Fda\FdaEstablishments\RelationManagers;

use App\Filament\Support\RecordActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OperationsRelationManager extends RelationManager
{
    protected static string $relationship = 'operations';

    protected static ?string $title = 'Operations';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('operation_code')->required()->maxLength(80),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('operation_code')->badge()->searchable(),
                TextColumn::make('created_at')->dateTime()->toggleable(),
            ])
            ->defaultSort('operation_code')
            ->recordActions(RecordActionGroup::make([
                EditAction::make(),
            ]));
    }
}
