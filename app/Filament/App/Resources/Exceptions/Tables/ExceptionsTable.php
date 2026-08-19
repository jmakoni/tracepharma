<?php

namespace App\Filament\App\Resources\Exceptions\Tables;

use App\Enums\ExceptionReceiveImpact;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\ExceptionTypeCategory;
use App\Models\Exceptions\ExceptionCase;
use App\Support\Exceptions\ExceptionCorrectionProfile;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExceptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                // Laravel passes Relation instances into with() constraints, not Builder.
                'type' => fn ($q) => $q->select(['id', 'name', 'category', 'receive_impact']),
                'tradingPartner' => fn ($q) => $q->select(['id', 'name']),
                'assignee' => fn ($q) => $q->select(['id', 'name']),
            ]))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable()
                    ->limit(40)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('type.name')
                    ->label('Type')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('type.receive_impact')
                    ->label('Receive impact')
                    ->badge()
                    ->formatStateUsing(fn (?ExceptionReceiveImpact $state): ?string => $state?->label())
                    ->color(fn (?ExceptionReceiveImpact $state): string => $state?->badgeColor() ?? 'gray')
                    ->toggleable(),
                TextColumn::make('severity')
                    ->badge()
                    ->formatStateUsing(fn (?ExceptionSeverity $state): ?string => $state?->label())
                    ->color(fn (?ExceptionSeverity $state): string => $state?->badgeColor() ?? 'gray')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?ExceptionStatus $state): ?string => $state?->label())
                    ->color(fn (?ExceptionStatus $state): string => $state?->badgeColor() ?? 'gray')
                    ->sortable(),
                TextColumn::make('tradingPartner.name')
                    ->label('Partner')
                    ->placeholder('—')
                    ->limit(28)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('assignee.name')
                    ->label('Assignee')
                    ->placeholder('—')
                    ->limit(24)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('due_at')
                    ->label('Due')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—')
                    ->color(fn (ExceptionCase $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->weight(fn (ExceptionCase $record): ?FontWeight => $record->isOverdue() ? FontWeight::Bold : null),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ExceptionStatus::cases())
                        ->mapWithKeys(fn (ExceptionStatus $status): array => [$status->value => $status->label()])
                        ->all()),
                SelectFilter::make('severity')
                    ->options(collect(ExceptionSeverity::cases())
                        ->mapWithKeys(fn (ExceptionSeverity $severity): array => [$severity->value => $severity->label()])
                        ->all()),
                SelectFilter::make('category')
                    ->label('Category')
                    ->options(collect(ExceptionTypeCategory::cases())
                        ->mapWithKeys(fn (ExceptionTypeCategory $category): array => [
                            $category->value => $category->label(),
                        ])
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! filled($value)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'type',
                            fn (Builder $q): Builder => $q->where('category', $value),
                        );
                    }),
                SelectFilter::make('receive_impact')
                    ->label('Receive impact')
                    ->options(collect(ExceptionReceiveImpact::cases())
                        ->mapWithKeys(fn (ExceptionReceiveImpact $impact): array => [
                            $impact->value => $impact->label(),
                        ])
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! filled($value)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'type',
                            fn (Builder $q): Builder => $q->where('receive_impact', $value),
                        );
                    }),
                SelectFilter::make('exception_type_id')
                    ->label('Type')
                    ->relationship(
                        'type',
                        'name',
                        fn (Builder $query): Builder => $query->whereNotIn(
                            'code',
                            ExceptionCorrectionProfile::operatorHiddenStubCodes(),
                        ),
                    )
                    ->searchable()
                    ->preload(),
                SelectFilter::make('trading_partner_id')
                    ->label('Partner')
                    ->relationship('tradingPartner', 'name')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('assigned_to_me')
                    ->label('Assigned to me')
                    ->trueLabel('Assigned to me')
                    ->falseLabel('Unassigned')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->assignedTo(auth()->id()),
                        false: fn (Builder $query): Builder => $query->whereNull('assigned_to'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Filter::make('overdue')
                    ->label('Overdue')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->overdue()),
                Filter::make('created_at')
                    ->label('Created')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Created from'),
                        DatePicker::make('until')
                            ->label('Created until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                filled($data['from'] ?? null),
                                fn (Builder $q): Builder => $q->whereDate('created_at', '>=', $data['from']),
                            )
                            ->when(
                                filled($data['until'] ?? null),
                                fn (Builder $q): Builder => $q->whereDate('created_at', '<=', $data['until']),
                            );
                    }),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
