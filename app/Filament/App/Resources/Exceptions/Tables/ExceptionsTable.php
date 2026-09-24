<?php

namespace App\Filament\App\Resources\Exceptions\Tables;

use App\Actions\Epcis\ReevaluateEpcisDocumentFindings;
use App\Actions\Exceptions\RecheckReceiveExceptionCondition;
use App\Enums\ExceptionReceiveImpact;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\ExceptionTypeCategory;
use App\Models\Exceptions\ExceptionCase;
use App\Support\Exceptions\ExceptionCorrectionProfile;
use App\Support\Exceptions\ExceptionHonestyStatus;
use App\Support\Receiving\ReceiveExceptionTypes;
use App\Support\Receiving\ReceiveSessionExceptionQuery;
use App\Support\TenantFeatures;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
                'principal' => fn ($q) => $q->select(['id', 'name']),
            ]))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable()
                    ->limit(40)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('principal.name')
                    ->label('Principal')
                    ->placeholder('—')
                    ->toggleable()
                    ->visible(fn (): bool => TenantFeatures::forTenant(tenant())->supportsPrincipals()),
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
                TextColumn::make('condition_still_true')
                    ->label('Condition')
                    ->badge()
                    ->visible(fn (): bool => ExceptionCase::hasHonestyColumns())
                    ->state(fn (ExceptionCase $record): string => ExceptionHonestyStatus::conditionLabel($record))
                    ->color(fn (string $state): string => $state === 'cleared' ? 'success' : 'warning'),
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
                SelectFilter::make('honesty_board')
                    ->label('Board')
                    ->options([
                        'open' => 'Open + waiting partner',
                        'cleared_resolved' => 'Cleared / resolved',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if ($value === 'open') {
                            return $query->open();
                        }
                        if ($value === 'cleared_resolved') {
                            return $query->whereIn('status', [
                                ExceptionStatus::Cleared->value,
                                ExceptionStatus::Resolved->value,
                                ExceptionStatus::Overridden->value,
                                ExceptionStatus::Closed->value,
                            ]);
                        }

                        return $query;
                    }),
                SelectFilter::make('condition')
                    ->label('Condition')
                    ->options([
                        'still_true' => 'still true',
                        'cleared' => 'cleared',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if ($value === 'still_true') {
                            return $query->conditionStillTrue()->whereNotIn('status', [
                                ExceptionStatus::Cleared->value,
                            ]);
                        }
                        if ($value === 'cleared') {
                            return $query->conditionCleared();
                        }

                        return $query;
                    }),
                SelectFilter::make('principal_id')
                    ->label('Principal')
                    ->relationship('principal', 'name')
                    ->searchable()
                    ->preload()
                    ->visible(fn (): bool => TenantFeatures::forTenant(tenant())->supportsPrincipals()),
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
                Filter::make('receiving_session_id')
                    ->label('Receiving session')
                    ->schema([
                        TextInput::make('value')
                            ->label('Receiving session')
                            ->numeric(),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $sessionId = (int) ($data['value'] ?? 0);

                        return ReceiveSessionExceptionQuery::constrainToSession($query, $sessionId);
                    }),
                Filter::make('type_code')
                    ->label('Type code')
                    ->schema([
                        TextInput::make('value')
                            ->label('Type code'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $raw = trim((string) ($data['value'] ?? ''));
                        if ($raw === '') {
                            return $query;
                        }

                        $codes = array_values(array_filter(array_map(
                            static fn (string $code): string => strtoupper(trim($code)),
                            explode(',', $raw),
                        )));

                        if ($codes === []) {
                            return $query;
                        }

                        $allowed = array_keys(ReceiveExceptionTypes::typeMap());
                        $codes = array_values(array_intersect($codes, $allowed));

                        if ($codes === []) {
                            return $query;
                        }

                        return $query->whereHas(
                            'type',
                            fn (Builder $types): Builder => $types->whereIn('code', $codes),
                        );
                    }),
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
            ->toolbarActions([
                BulkAction::make('recheckCondition')
                    ->label('Re-check')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalHeading('Re-check selected exceptions?')
                    ->modalDescription('Re-runs the type predicate. Cleared conditions leave the open board. Still-true cases keep their SLA clock.')
                    ->action(function ($records): void {
                        $recheck = app(RecheckReceiveExceptionCondition::class);
                        $actor = auth()->user();
                        $cleared = 0;
                        $stillTrue = 0;
                        foreach ($records as $record) {
                            if (! $record instanceof ExceptionCase) {
                                continue;
                            }
                            $fresh = $recheck->handle($record, $actor);
                            if ($fresh->status === ExceptionStatus::Cleared) {
                                $cleared++;
                            } elseif ($fresh->status?->isOpen()) {
                                $stillTrue++;
                            }
                        }

                        Notification::make()
                            ->title('Re-check complete')
                            ->body($cleared.' cleared · '.$stillTrue.' still true')
                            ->success()
                            ->send();
                    }),
                BulkAction::make('reevaluateFindings')
                    ->label('Re-evaluate findings')
                    ->icon('heroicon-o-magnifying-glass')
                    ->requiresConfirmation()
                    ->modalHeading('Re-evaluate findings on selected hard-blocks?')
                    ->modalDescription('Runs the current validator on each linked document’s stored file. Events and receiving sessions are not rewritten. Only still-true hard-blocking cases are considered.')
                    ->action(function ($records): void {
                        $eligible = collect($records)->filter(function ($record): bool {
                            if (! $record instanceof ExceptionCase || $record->document_id === null) {
                                return false;
                            }

                            if ($record->status?->isOpen() !== true) {
                                return false;
                            }

                            if ($record->hasHonestyColumns() && $record->condition_still_true === false) {
                                return false;
                            }

                            return $record->type?->receive_impact === ExceptionReceiveImpact::HardBlocking;
                        });

                        $result = app(ReevaluateEpcisDocumentFindings::class)->handleCases(
                            $eligible,
                            auth()->user(),
                        );

                        Notification::make()
                            ->title('Findings re-evaluated')
                            ->body(count($result['cleared']).' cleared · '.count($result['left_open']).' still emitted')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
