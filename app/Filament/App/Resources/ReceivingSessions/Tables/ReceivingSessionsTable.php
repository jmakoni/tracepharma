<?php

namespace App\Filament\App\Resources\ReceivingSessions\Tables;

use App\Actions\Receiving\CancelReceivingSession;
use App\Actions\Receiving\DeleteReceivingSession;
use App\Enums\ReceivingSessionKind;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use App\Filament\Support\Floor\UnsubmittedSessionDeleteAction;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Receiving\ReceivingSession;
use App\Support\Auth\CurrentSite;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ReceiveLayout;
use App\Support\Receiving\ReceivingSessionStatus;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReceivingSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['document', 'tradingPartner', 'site', 'transferringSession', 'openedByUser']))
            ->columns([
                TextColumn::make('session_kind')
                    ->label('Kind')
                    ->badge()
                    ->formatStateUsing(function (ReceivingSessionKind|string|null $state): string {
                        if ($state instanceof ReceivingSessionKind) {
                            return $state->badgeLabel();
                        }

                        return ReceivingSessionKind::tryFrom((string) $state)?->badgeLabel()
                            ?? ReceivingSessionKind::InboundAsn->badgeLabel();
                    })
                    ->color(fn (ReceivingSessionKind|string|null $state): string => match (
                        $state instanceof ReceivingSessionKind ? $state : ReceivingSessionKind::tryFrom((string) $state)
                    ) {
                        ReceivingSessionKind::ScanFirst => 'info',
                        ReceivingSessionKind::TransferReceive => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('site.name')
                    ->label('Site')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ReceivingSessionStatus::label($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'completed' => 'success',
                        'in_progress' => 'warning',
                        'open' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('openedByUser.name')
                    ->label('User')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('source')
                    ->label('Source')
                    ->state(function (ReceivingSession $record): string {
                        if (filled($record->document?->original_filename)) {
                            return (string) $record->document->original_filename;
                        }

                        if ($record->transferring_session_id !== null) {
                            return 'Transfer #'.$record->transferring_session_id;
                        }

                        return '—';
                    })
                    ->limit(36)
                    ->tooltip(fn (ReceivingSession $record): ?string => filled($record->document?->original_filename)
                        ? (string) $record->document->original_filename
                        : ($record->transferring_session_id !== null
                            ? 'Transfer #'.$record->transferring_session_id
                            : null))
                    ->url(fn (ReceivingSession $record): ?string => $record->transferring_session_id !== null
                        ? TransferringSessionResource::getUrl('view', ['record' => $record->transferring_session_id])
                        : null)
                    ->color(fn (ReceivingSession $record): ?string => $record->transferring_session_id !== null ? 'primary' : null)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $inner) use ($search): void {
                            $inner->whereHas('document', fn (Builder $doc) => $doc
                                ->where('original_filename', 'like', "%{$search}%"))
                                ->orWhere('transferring_session_id', 'like', "%{$search}%");
                        });
                    }),
                TextColumn::make('expected_parent_count')
                    ->label('Parents')
                    ->formatStateUsing(fn ($state, ReceivingSession $record): string => ((int) $record->confirmed_parent_count).'/'.((int) $state))
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('expected_child_count')
                    ->label('Children')
                    ->formatStateUsing(fn ($state, ReceivingSession $record): string => ((int) $record->confirmed_child_count).'/'.((int) $state))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('opened_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('document.document_uuid')
                    ->label('UUID')
                    ->limit(12)
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->searchDebounce('500ms')
            ->filters([
                SelectFilter::make('session_kind')
                    ->label('Kind')
                    ->options(collect(ReceivingSessionKind::cases())
                        ->mapWithKeys(fn (ReceivingSessionKind $kind): array => [
                            $kind->value => $kind->badgeLabel(),
                        ])
                        ->all()),
                SelectFilter::make('status')
                    ->options([
                        'open' => ReceivingSessionStatus::label('open'),
                        'in_progress' => ReceivingSessionStatus::label('in_progress'),
                        'completed' => ReceivingSessionStatus::label('completed'),
                        'cancelled' => ReceivingSessionStatus::label('cancelled'),
                    ]),
                SelectFilter::make('site_id')
                    ->label('Site')
                    ->options(fn (): array => EligibleReceiveSites::options())
                    ->searchable()
                    ->default(fn (): ?int => CurrentSite::id()),
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
                    ->url(fn (ReceivingSession $record): string => ReceiveLayout::sessionUrl($record)),
                RegulatoryCompliance::apply(
                    Action::make('cancelReceiving')
                        ->label('Cancel')
                        ->icon(Heroicon::OutlinedXMark)
                        ->color('danger')
                        ->visible(fn (ReceivingSession $record): bool => $record->canCancel())
                        ->requiresConfirmation()
                        ->modalHeading('Cancel this receive?')
                        ->modalDescription('Marks the session cancelled and removes it from Active receives. Scan history is kept.')
                        ->modalSubmitActionLabel('Cancel receive')
                        ->action(function (ReceivingSession $record): void {
                            try {
                                app(CancelReceivingSession::class)->handle($record, auth()->id());
                            } catch (DomainException $e) {
                                Notification::make()
                                    ->title('Cancel blocked')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title('Receive cancelled')
                                ->success()
                                ->send();
                        }),
                    'receiving_cancel',
                    requireReason: true,
                ),
                UnsubmittedSessionDeleteAction::forReceiving(
                    fn (ReceivingSession $record) => app(DeleteReceivingSession::class)->handle($record, auth()->id()),
                    '',
                ),
            ]);
    }
}
