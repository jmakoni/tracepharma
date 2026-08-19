<?php

namespace App\Filament\Admin\Resources\Fda\FdaProducts\RelationManagers;

use App\Filament\Support\RecordActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RoutesRelationManager extends RelationManager
{
    protected static string $relationship = 'routes';

    protected static ?string $title = 'Routes';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('route_name')->required()->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('route_name')->badge()->searchable(),
            ])
            ->defaultSort('route_name')
            ->recordActions(RecordActionGroup::make([
                EditAction::make(),
            ]));
    }
}
