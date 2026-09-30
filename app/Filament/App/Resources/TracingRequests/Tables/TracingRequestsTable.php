<?php

namespace App\Filament\App\Resources\TracingRequests\Tables;

use App\Enums\TracingRequestorType;
use App\Enums\TracingRequestStatus;
use App\Models\TracingRequest;
use App\Models\User;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class TracingRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['requestedByUser']))
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(40)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('title')
                            ->options(fn (): array => DistinctColumnOptions::of(TracingRequest::class, 'title')),
                    ),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (TracingRequestStatus $state): string => $state->label())
                    ->color(fn (TracingRequest $record): string => match (true) {
                        $record->status === TracingRequestStatus::Cancelled => 'gray',
                        $record->isOverdue() || $record->sla_breached => 'danger',
                        $record->status === TracingRequestStatus::Completed => 'success',
                        $record->status === TracingRequestStatus::InProgress => 'warning',
                        default => 'info',
                    })
                    ->sortable()
                    ->columnFilter(ColumnFilter::select()->syncWith('status')),
                TextColumn::make('requestor_type')
                    ->label('Requestor')
                    ->badge()
                    ->formatStateUsing(fn (TracingRequestorType $state): string => $state->label())
                    ->sortable()
                    ->columnFilter(ColumnFilter::select()->syncWith('requestor_type')),
                TextColumn::make('gtin')
                    ->label('GTIN')
                    ->searchable()
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('gtin')
                            ->options(fn (): array => DistinctColumnOptions::of(TracingRequest::class, 'gtin')),
                    ),
                TextColumn::make('lot')
                    ->searchable()
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('lot')
                            ->options(fn (): array => DistinctColumnOptions::of(TracingRequest::class, 'lot')),
                    ),
                TextColumn::make('due_at')
                    ->label('SLA due')
                    ->dateTime()
                    ->sortable()
                    ->color(fn (TracingRequest $record): string => $record->isOverdue() || $record->sla_breached ? 'danger' : 'gray')
                    ->columnFilter(ColumnFilter::date()),
                IconColumn::make('sla_breached')
                    ->label('Breached')
                    ->boolean()
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('sla_breached')
                            ->options(DistinctColumnOptions::boolean()),
                    ),
                IconColumn::make('is_recall')
                    ->label('Recall')
                    ->boolean()
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('is_recall')
                            ->options(DistinctColumnOptions::boolean()),
                    ),
                TextColumn::make('requestedByUser.name')
                    ->label('Opened by')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('requested_by')
                            ->options(fn (): array => DistinctColumnOptions::related(
                                TracingRequest::class,
                                'requested_by',
                                User::class,
                            )),
                    ),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(ColumnFilter::date()),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(TracingRequestStatus::cases())->mapWithKeys(
                        fn (TracingRequestStatus $status): array => [$status->value => $status->label()]
                    )),
                SelectFilter::make('requestor_type')
                    ->label('Requestor')
                    ->options(collect(TracingRequestorType::cases())->mapWithKeys(
                        fn (TracingRequestorType $type): array => [$type->value => $type->label()]
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
