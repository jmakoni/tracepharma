<?php

namespace App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\Tables;

use App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\Support\MatchReviewActions;
use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Filament\Support\RecordActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FdaOrganizationMatchReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('proposedOrganization'))
            ->columns([
                FdaRegistryBadges::reviewStatusColumn(),
                TextColumn::make('source')->searchable(),
                TextColumn::make('original_name')->searchable()->limit(40),
                TextColumn::make('canonical_name')->searchable()->toggleable(),
                TextColumn::make('confidence')->numeric(2),
                TextColumn::make('proposedOrganization.name')->label('Proposed organization')->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'linked' => 'Linked',
                        'rejected' => 'Rejected',
                        'created_new' => 'Created New',
                    ])
                    ->default('pending'),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                ViewAction::make(),
                ...MatchReviewActions::all(),
            ]));
    }
}
