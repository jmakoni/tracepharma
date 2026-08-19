<?php

namespace App\Filament\App\Resources\TracingRequests\Tables;

use App\Enums\TracingRequestorType;
use App\Enums\TracingRequestStatus;
use App\Models\TracingRequest;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                    ->limit(40),
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
                    ->sortable(),
                TextColumn::make('requestor_type')
                    ->label('Requestor')
                    ->badge()
                    ->formatStateUsing(fn (TracingRequestorType $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('gtin')
                    ->label('GTIN')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('lot')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('due_at')
                    ->label('SLA due')
                    ->dateTime()
                    ->sortable()
                    ->color(fn (TracingRequest $record): string => $record->isOverdue() || $record->sla_breached ? 'danger' : 'gray'),
                IconColumn::make('sla_breached')
                    ->label('Breached')
                    ->boolean()
                    ->toggleable(),
                IconColumn::make('is_recall')
                    ->label('Recall')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('requestedByUser.name')
                    ->label('Opened by')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
