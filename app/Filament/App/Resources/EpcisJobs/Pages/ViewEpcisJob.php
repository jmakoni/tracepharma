<?php

namespace App\Filament\App\Resources\EpcisJobs\Pages;

use App\Actions\EpcisJobs\ArchiveEpcisJob;
use App\Actions\EpcisJobs\CancelEpcisJob;
use App\Actions\EpcisJobs\ForceFailEpcisJob;
use App\Actions\EpcisJobs\RequeueEpcisJob;
use App\Enums\EpcisJobKind;
use App\Enums\EpcisJobStatus;
use App\Filament\App\Resources\EpcisJobs\EpcisJobResource;
use App\Models\EpcisJob;
use App\Support\EpcisJobs\EpcisJobSla;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use RuntimeException;
use Throwable;

class ViewEpcisJob extends ViewRecord
{
    protected static string $resource = EpcisJobResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->loadMissing([
            'document',
            'outboundConnection',
            'shipFromSite',
            'requestedByUser',
            'messages',
        ]);
    }

    public function getHeading(): string|Htmlable|null
    {
        /** @var EpcisJob $record */
        $record = $this->getRecord();

        return $record->receipt;
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var EpcisJob $record */
        $record = $this->getRecord();

        $status = $record->status?->label() ?? '—';
        $kind = $record->kind?->label() ?? '—';

        return "{$status} · {$kind}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label('Cancel')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => $this->getRecord()->status === EpcisJobStatus::Queued
                    ? 'Cancel this queued job before a worker picks it up.'
                    : 'Cancel this stuck job that exceeded the worker timeout.')
                ->visible(fn (): bool => EpcisJobSla::canCancel($this->getRecord()))
                ->action(function (): void {
                    try {
                        app(CancelEpcisJob::class)->handle($this->getRecord());
                        $this->getRecord()->refresh();
                        $this->refreshFormData(['status', 'finished_at', 'error_message']);
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
                ->modalDescription('Mark this stuck job as failed so it can be requeued. Use when the worker timed out or died.')
                ->visible(fn (): bool => EpcisJobSla::canForceFail($this->getRecord()))
                ->action(function (): void {
                    try {
                        app(ForceFailEpcisJob::class)->handle($this->getRecord());
                        $this->getRecord()->refresh();
                        $this->refreshFormData(['status', 'finished_at', 'error_message']);
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
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => $this->getRecord()->kind === EpcisJobKind::InboundProcess
                    ? 'Reprocess the inbound document and create a new job receipt.'
                    : 'Rebuild the outbound payload if needed and enqueue a new job receipt.')
                ->visible(fn (): bool => in_array(
                    $this->getRecord()->status,
                    [EpcisJobStatus::Error, EpcisJobStatus::Cancelled],
                    true,
                ))
                ->action(function (): void {
                    try {
                        $newJob = app(RequeueEpcisJob::class)->handle(
                            $this->getRecord(),
                            auth()->id(),
                        );

                        Notification::make()
                            ->title('Job requeued')
                            ->body('New receipt: '.$newJob->receipt)
                            ->success()
                            ->send();

                        $this->redirect(EpcisJobResource::getUrl('view', ['record' => $newJob]));
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
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Hide this terminal job from the default list.')
                ->visible(fn (): bool => ($this->getRecord()->status?->isTerminal() ?? false)
                    && $this->getRecord()->archived_at === null)
                ->action(function (): void {
                    try {
                        app(ArchiveEpcisJob::class)->handle($this->getRecord());
                        Notification::make()
                            ->title('Job archived')
                            ->success()
                            ->send();
                        $this->redirect(EpcisJobResource::getUrl('index'));
                    } catch (RuntimeException $e) {
                        Notification::make()
                            ->title('Archive failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('viewDocument')
                ->label('View document')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->document !== null)
                ->url(fn (): ?string => $this->getRecord()->document?->filamentViewUrl()),
        ];
    }
}
