<?php

namespace App\Filament\App\Resources\Exceptions\RelationManagers;

use App\Enums\ExceptionActivityKind;
use App\Enums\ExceptionActivityVisibility;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Activity';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                // Laravel passes Relation instances into with() constraints, not Builder.
                'user' => fn ($q) => $q->select(['id', 'name']),
            ]))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn (?ExceptionActivityKind $state): ?string => $state?->label())
                    ->color(fn (?ExceptionActivityKind $state): string => match ($state) {
                        ExceptionActivityKind::StatusChange => 'warning',
                        ExceptionActivityKind::Assignment => 'info',
                        ExceptionActivityKind::Comment => 'gray',
                        ExceptionActivityKind::Resolution => 'success',
                        ExceptionActivityKind::System => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('visibility')
                    ->badge()
                    ->formatStateUsing(fn (?ExceptionActivityVisibility $state): ?string => $state?->label())
                    ->color(fn (?ExceptionActivityVisibility $state): string => match ($state) {
                        ExceptionActivityVisibility::Partner => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('user.name')
                    ->label('User')
                    ->placeholder('System'),
                TextColumn::make('body')
                    ->wrap()
                    ->limit(80)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->headerActions([])
            ->recordActions([])
            ->emptyStateHeading('No activity')
            ->emptyStateDescription('Comments and status changes will appear here.');
    }
}
