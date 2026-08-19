<?php

namespace App\Filament\App\Resources\EpcisDocuments\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UnmatchedGlnsRelationManager extends RelationManager
{
    protected static string $relationship = 'unmatchedGlns';

    protected static ?string $title = 'Unmatched GLNs';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('gln')
                    ->label('GLN')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('context')
                    ->badge()
                    ->sortable(),
                TextColumn::make('gln_uri')
                    ->label('URI')
                    ->limit(40)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tradingPartner.name')
                    ->label('Partner (partial)')
                    ->placeholder('—'),
                TextColumn::make('site.name')
                    ->label('Site (partial)')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Recorded')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('context')
                    ->options([
                        'sender' => 'sender',
                        'receiver' => 'receiver',
                        'source' => 'source',
                        'destination' => 'destination',
                        'readPoint' => 'readPoint',
                        'bizLocation' => 'bizLocation',
                    ]),
            ])
            ->deferLoading()
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No unmatched GLNs')
            ->emptyStateDescription('All GLNs in this document resolved to master data.')
            ->headerActions([])
            ->recordActions([]);
    }
}
