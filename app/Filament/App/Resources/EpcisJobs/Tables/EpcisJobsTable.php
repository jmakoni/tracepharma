<?php

namespace App\Filament\App\Resources\EpcisJobs\Tables;

use App\Actions\EpcisJobs\ArchiveEpcisJob;
use App\Actions\EpcisJobs\CancelEpcisJob;
use App\Actions\EpcisJobs\ForceFailEpcisJob;
use App\Actions\EpcisJobs\RequeueEpcisJob;
use App\Enums\EpcisJobKind;
use App\Enums\EpcisJobStatus;
use App\Filament\App\Resources\EpcisJobs\EpcisJobResource;
use App\Filament\Support\RecordActionGroup;
use App\Models\EpcisJob;
use App\Support\EpcisJobs\EpcisJobSla;
use App\Support\TenantFeatures;
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
use RuntimeException;
use Throwable;

class EpcisJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'requestedByUser',
                'outboundConnection',
                'shipFromSite',
                'document',
            ]))
            ->columns([
                TextColumn::make('receipt')
                    ->label('Receipt')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->copyable()
                    ->limit(12)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->sortable(),
                TextColumn::make('kind')
                    ->label('Kind')
                    ->formatStateUsing(fn (?EpcisJobKind $state): string => $state?->label() ?? '—')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?EpcisJobStatus $state): string => $state?->label() ?? '—')
                    ->color(fn (?EpcisJobStatus $state): string => match ($state) {
                        EpcisJobStatus::Complete => 'success',
                        EpcisJobStatus::Error => 'danger',
                        EpcisJobStatus::Queued, EpcisJobStatus::Sending, EpcisJobStatus::Processing => 'warning',
                        EpcisJobStatus::Cancelled => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('requestedByUser.name')
                    ->label('Requested by')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('received_at')
                    ->label('Received')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('outboundConnection.name')
                    ->label('Connection')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('shipFromSite.name')
                    ->label('Ship-from')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('original_filename')
                    ->label('Filename')
                    ->limit(28)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('document.asn_number')
                    ->label('ASN')
                    ->fontFamily(FontFamily::Mono)
                    ->limit(16)
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('received_at', 'desc')
            ->searchDebounce('500ms')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(EpcisJobStatus::cases())
                        ->mapWithKeys(fn (EpcisJobStatus $case): array => [$case->value => $case->label()])
                        ->all()),
                SelectFilter::make('kind')
                    ->options(collect(EpcisJobKind::cases())
                        ->mapWithKeys(fn (EpcisJobKind $case): array => [$case->value => $case->label()])
                        ->all())
                    ->default(function (): ?string {
                        $features = TenantFeatures::forTenant(tenant());

                        if (
                            $features->supportsInboundIntegrations()
                            && ! $features->supportsOutboundIntegrations()
                            && ! $features->supportsTransferring()
                            && ! $features->supportsSsccLabeling()
                        ) {
                            return EpcisJobKind::InboundProcess->value;
                        }

                        return null;
                    }),
                Filter::make('received_at')
                    ->label('Date')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (filled($data['from'] ?? null)) {
                            $query->whereDate('received_at', '>=', $data['from']);
                        }
                        if (filled($data['until'] ?? null)) {
                            $query->whereDate('received_at', '<=', $data['until']);
                        }

                        return $query;
                    }),
            ], FiltersLayout::Modal)
            ->filtersFormColumns(2)
            ->filtersFormWidth(Width::FourExtraLarge)
            ->deferLoading()
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordUrl(fn (EpcisJob $record): string => EpcisJobResource::getUrl('view', ['record' => $record]))
            ->recordActions(RecordActionGroup::make([
                ViewAction::make(),
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->requiresConfirmation()
                    ->modalDescription(fn (EpcisJob $record): string => $record->status === EpcisJobStatus::Queued
                        ? 'Cancel this queued job before a worker picks it up.'
                        : 'Cancel this stuck job that exceeded the worker timeout.')
                    ->visible(fn (EpcisJob $record): bool => EpcisJobSla::canCancel($record))
                    ->action(function (EpcisJob $record): void {
                        try {
                            app(CancelEpcisJob::class)->handle($record);
                            Notification::make()
                                ->title('Job cancelled')
                                ->success()
                                ->send();
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->title('Cancel failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('forceFail')
                    ->label('Force fail')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Mark this stuck job as failed so it can be requeued.')
                    ->visible(fn (EpcisJob $record): bool => EpcisJobSla::canForceFail($record))
                    ->action(function (EpcisJob $record): void {
                        try {
                            app(ForceFailEpcisJob::class)->handle($record);
                            Notification::make()
                                ->title('Job force-failed')
                                ->success()
                                ->send();
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->title('Force fail failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('requeue')
                    ->label('Requeue')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->modalDescription(fn (EpcisJob $record): string => $record->kind === EpcisJobKind::InboundProcess
                        ? 'Reprocess the inbound document and create a new job receipt.'
                        : 'Rebuild the outbound payload if needed and enqueue a new job receipt.')
                    ->visible(fn (EpcisJob $record): bool => in_array(
                        $record->status,
                        [EpcisJobStatus::Error, EpcisJobStatus::Cancelled],
                        true,
                    ))
                    ->action(function (EpcisJob $record): void {
                        try {
                            $newJob = app(RequeueEpcisJob::class)->handle($record, auth()->id());
                            Notification::make()
                                ->title('Job requeued')
                                ->body('New receipt: '.$newJob->receipt)
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Requeue failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('archive')
                    ->label('Archive')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->requiresConfirmation()
                    ->modalDescription('Hide this terminal job from the default list.')
                    ->visible(fn (EpcisJob $record): bool => ($record->status?->isTerminal() ?? false)
                        && $record->archived_at === null)
                    ->action(function (EpcisJob $record): void {
                        try {
                            app(ArchiveEpcisJob::class)->handle($record);
                            Notification::make()
                                ->title('Job archived')
                                ->success()
                                ->send();
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->title('Archive failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]));
    }
}
