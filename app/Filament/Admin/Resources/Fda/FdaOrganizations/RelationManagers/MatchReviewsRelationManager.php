<?php

namespace App\Filament\Admin\Resources\Fda\FdaOrganizations\RelationManagers;

use App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\FdaOrganizationMatchReviewResource;
use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Filament\Support\RecordActionGroup;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MatchReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'matchReviews';

    protected static ?string $title = 'Match History';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                FdaRegistryBadges::reviewStatusColumn(),
                TextColumn::make('source'),
                TextColumn::make('original_name')->limit(40),
                TextColumn::make('confidence'),
                TextColumn::make('resolved_at')->dateTime()->since(),
            ])
            ->defaultSort('resolved_at', 'desc')
            ->recordUrl(fn ($record): string => FdaOrganizationMatchReviewResource::getUrl('view', ['record' => $record]))
            ->recordActions(RecordActionGroup::make([
                ViewAction::make()
                    ->url(fn ($record): string => FdaOrganizationMatchReviewResource::getUrl('view', ['record' => $record])),
            ]));
    }
}
