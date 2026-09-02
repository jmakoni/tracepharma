<?php

namespace App\Filament\Admin\Resources\Fda\FdaOrganizations\RelationManagers;

use App\Filament\Admin\Resources\Fda\FdaEstablishments\FdaEstablishmentResource;
use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Filament\Support\RecordActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EstablishmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'establishments';

    protected static ?string $title = 'Establishments';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                FdaRegistryBadges::identifierColumn('fei_number', 'FEI'),
                TextColumn::make('name')->searchable()->placeholder(fn ($record) => $record->firm_name),
                TextColumn::make('city'),
                TextColumn::make('state_province')->label('State'),
                FdaRegistryBadges::establishmentColumn(),
                FdaRegistryBadges::activeColumn(),
            ])
            ->recordUrl(fn ($record): string => FdaEstablishmentResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                CreateAction::make()
                    ->label('New establishment')
                    ->url(fn (): string => FdaEstablishmentResource::getUrl('create', [
                        'fda_organization_id' => $this->getOwnerRecord()->getKey(),
                    ]))
                    ->visible(fn (): bool => FdaEstablishmentResource::canCreate()),
            ])
            ->recordActions(RecordActionGroup::make([
                ViewAction::make()
                    ->url(fn ($record): string => FdaEstablishmentResource::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->url(fn ($record): string => FdaEstablishmentResource::getUrl('edit', ['record' => $record])),
            ]));
    }
}
