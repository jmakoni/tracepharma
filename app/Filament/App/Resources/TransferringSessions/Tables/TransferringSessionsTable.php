<?php

namespace App\Filament\App\Resources\TransferringSessions\Tables;

use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Actions\Transferring\DeleteTransferringSession;
use App\Filament\Support\Floor\UnsubmittedSessionDeleteAction;
use App\Models\Transferring\TransferringSession;
use App\Support\Auth\CurrentSite;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Transferring\TransferLayout;
use App\Support\Transferring\TransferringSessionStatus;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TransferringSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'fromSite',
                'toSite',
                'receivingSession',
            ]))
            ->columns([
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => TransferringSessionStatus::label($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'completed' => 'success',
                        'in_transit' => 'info',
                        'open' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('fromSite.name')
                    ->label('From')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('toSite.name')
                    ->label('To')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('confirmed_count')
                    ->label('Confirmed')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('received_count')
                    ->label('Received')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('opened_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('shipped_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('received_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('completed_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('receivingSession.id')
                    ->label('Receive')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? '#'.$state : '—')
                    ->url(fn (TransferringSession $record): ?string => $record->receivingSession !== null
                        ? ReceivingSessionResource::getUrl('view', ['record' => $record->receivingSession])
                        : null)
                    ->color('primary')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->searchDebounce('500ms')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'open' => TransferringSessionStatus::label('open'),
                        'in_transit' => TransferringSessionStatus::label('in_transit'),
                        'completed' => TransferringSessionStatus::label('completed'),
                    ]),
                SelectFilter::make('site')
                    ->label('Site')
                    ->options(fn (): array => EligibleReceiveSites::options())
                    ->searchable()
                    ->default(fn (): ?int => CurrentSite::id())
                    ->query(function (Builder $query, array $data): Builder {
                        $siteId = $data['value'] ?? null;
                        if (blank($siteId)) {
                            return $query;
                        }

                        return $query->where(function (Builder $scoped) use ($siteId): void {
                            $scoped
                                ->where('from_site_id', $siteId)
                                ->orWhere('to_site_id', $siteId);
                        });
                    }),
                Filter::make('opened_at')
                    ->label('Opened')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (filled($data['from'] ?? null)) {
                            $query->whereDate('opened_at', '>=', $data['from']);
                        }
                        if (filled($data['until'] ?? null)) {
                            $query->whereDate('opened_at', '<=', $data['until']);
                        }

                        return $query;
                    }),
                Filter::make('completed_at')
                    ->label('Completed')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (filled($data['from'] ?? null)) {
                            $query->whereDate('completed_at', '>=', $data['from']);
                        }
                        if (filled($data['until'] ?? null)) {
                            $query->whereDate('completed_at', '<=', $data['until']);
                        }

                        return $query;
                    }),
            ], FiltersLayout::Modal)
            ->filtersFormColumns(2)
            ->filtersFormWidth(Width::FourExtraLarge)
            ->recordActions([
                ViewAction::make()
                    ->url(fn (TransferringSession $record): string => TransferLayout::sessionUrl($record)),
                UnsubmittedSessionDeleteAction::forTransfer(
                    fn (TransferringSession $record) => app(DeleteTransferringSession::class)->handle($record, auth()->id()),
                    '',
                ),
            ]);
    }
}
