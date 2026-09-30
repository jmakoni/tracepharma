<?php

namespace App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\Tables;

use App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\Support\MatchReviewActions;
use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Filament\Support\RecordActionGroup;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaOrganizationMatchReview;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class FdaOrganizationMatchReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('proposedOrganization'))
            ->columns([
                FdaRegistryBadges::reviewStatusColumn()
                    ->columnFilter(ColumnFilter::select()->syncWith('status')),
                TextColumn::make('source')
                    ->searchable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('source')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaOrganizationMatchReview::class, 'source')),
                    ),
                TextColumn::make('original_name')
                    ->searchable()
                    ->limit(40)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('original_name')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaOrganizationMatchReview::class, 'original_name')),
                    ),
                TextColumn::make('canonical_name')
                    ->searchable()
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('canonical_name')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaOrganizationMatchReview::class, 'canonical_name')),
                    ),
                TextColumn::make('confidence')
                    ->numeric(2)
                    ->columnFilter(ColumnFilter::range()),
                TextColumn::make('proposedOrganization.name')
                    ->label('Proposed organization')
                    ->placeholder('—')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('proposed_fda_organization_id')
                            ->options(fn (): array => DistinctColumnOptions::related(
                                FdaOrganizationMatchReview::class,
                                'proposed_fda_organization_id',
                                FdaOrganization::class,
                            )),
                    ),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->columnFilter(ColumnFilter::date()),
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
