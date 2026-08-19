<?php

namespace App\Filament\Admin\Resources\Fda\FdaProducts\RelationManagers;

use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Filament\Support\RecordActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PackagesRelationManager extends RelationManager
{
    protected static string $relationship = 'packaging';

    protected static ?string $title = 'Packages';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('package_ndc')->label('Package NDC')->required()->maxLength(20),
            TextInput::make('ndc11')->label('NDC-11')->maxLength(11),
            TextInput::make('gtin')->label('GTIN')->maxLength(14),
            TextInput::make('description')->maxLength(255),
            TextInput::make('net_content_description')->label('Net content')->maxLength(255),
            Toggle::make('is_active')->inline(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                FdaRegistryBadges::identifierColumn('package_ndc', 'Package NDC'),
                FdaRegistryBadges::identifierColumn('ndc11', 'NDC-11'),
                FdaRegistryBadges::identifierColumn('gtin', 'GTIN'),
                TextColumn::make('net_content_description')->label('Net content')->wrap()->limit(60),
                FdaRegistryBadges::activeColumn(),
            ])
            ->defaultSort('package_ndc')
            ->recordActions(RecordActionGroup::make([
                EditAction::make(),
            ]));
    }
}
