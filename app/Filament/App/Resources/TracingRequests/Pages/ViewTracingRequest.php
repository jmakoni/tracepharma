<?php

namespace App\Filament\App\Resources\TracingRequests\Pages;

use App\Actions\Tracing\SendRecallBroadcast;
use App\Actions\Tracing\SuggestRecallBroadcastRecipients;
use App\Enums\TracingRequestNotificationStatus;
use App\Enums\TracingRequestStatus;
use App\Filament\App\Resources\TracingRequests\TracingRequestResource;
use App\Models\TracingRequest;
use App\Models\TracingRequestNotification;
use App\Models\User;
use App\Services\Tracing\TracingRequestService;
use App\Services\Tracing\TracingSlaService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

class ViewTracingRequest extends ViewRecord
{
    protected static string $resource = TracingRequestResource::class;

    /** @var list<int> */
    public array $broadcastPartnerIds = [];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->loadMissing(['notifications.tradingPartner']);
    }

    protected function getHeaderActions(): array
    {
        return array_merge(
            $this->recallBroadcastHeaderActions(),
            $this->workflowHeaderActions(),
        );
    }

    /**
     * @return list<Action>
     */
    private function recallBroadcastHeaderActions(): array
    {
        return [
            Action::make('suggestRecallRecipients')
                ->label('Suggest recipients')
                ->icon(Heroicon::OutlinedUserGroup)
                ->color('gray')
                ->visible(fn (): bool => (bool) $this->getRecord()->is_recall)
                ->requiresConfirmation()
                ->modalHeading('Suggest recall recipients')
                ->modalDescription('Finds trading partners who received outbound shipments matching this recall GTIN/lot.')
                ->action(function (): void {
                    /** @var TracingRequest $record */
                    $record = $this->getRecord();

                    $partners = app(SuggestRecallBroadcastRecipients::class)->execute($record);
                    $this->broadcastPartnerIds = $partners
                        ->pluck('id')
                        ->map(fn (mixed $id): int => (int) $id)
                        ->values()
                        ->all();

                    foreach ($partners as $partner) {
                        TracingRequestNotification::query()->updateOrCreate(
                            [
                                'tracing_request_id' => $record->getKey(),
                                'trading_partner_id' => $partner->getKey(),
                                'channel' => 'email',
                            ],
                            [
                                'status' => TracingRequestNotificationStatus::Pending,
                                'error_message' => null,
                            ],
                        );
                    }

                    $this->refreshRecord();

                    Notification::make()
                        ->title($partners->isEmpty()
                            ? 'No matching recipients found'
                            : 'Suggested '.$partners->count().' recipient(s)')
                        ->body($partners->isEmpty()
                            ? 'No outbound shipments with a contact email matched this GTIN/lot.'
                            : 'Pending recall notices were prepared for partners with email addresses.')
                        ->color($partners->isEmpty() ? 'warning' : 'success')
                        ->send();
                }),
            Action::make('sendRecallNotice')
                ->label('Send recall notice')
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('danger')
                ->visible(fn (): bool => (bool) $this->getRecord()->is_recall
                    && $this->recallPartnerIdsForSend() !== [])
                ->requiresConfirmation()
                ->modalHeading('Send recall notice')
                ->modalDescription(fn (): string => 'Email recall notice to '
                    .count($this->recallPartnerIdsForSend())
                    .' trading partner(s) with contact email.')
                ->action(function (): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    /** @var TracingRequest $record */
                    $record = $this->getRecord();

                    try {
                        $result = app(SendRecallBroadcast::class)->execute(
                            $record,
                            $this->recallPartnerIdsForSend(),
                            $actor,
                        );
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()
                            ->title('Recall notice failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->refreshRecord();

                    if (($result['sent'] ?? 0) === 0) {
                        Notification::make()
                            ->title('No recall notices sent')
                            ->body(($result['failed'] ?? 0) > 0
                                ? 'All selected sends failed. Check notification status below.'
                                : 'No partners with email were selected.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Recall notice sent')
                        ->body(($result['sent'] ?? 0).' sent'
                            .(($result['failed'] ?? 0) > 0 ? ', '.($result['failed'] ?? 0).' failed' : '')
                            .'.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * @return list<int>
     */
    private function recallPartnerIdsForSend(): array
    {
        if ($this->broadcastPartnerIds !== []) {
            return $this->broadcastPartnerIds;
        }

        /** @var TracingRequest $record */
        $record = $this->getRecord();

        return $record->notifications()
            ->where('status', TracingRequestNotificationStatus::Pending)
            ->whereHas('tradingPartner', fn ($query) => $query
                ->whereNotNull('email')
                ->where('email', '!=', ''))
            ->pluck('trading_partner_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<Action>
     */
    private function workflowHeaderActions(): array
    {
        return [
            Action::make('start')
                ->label('Start work')
                ->icon('heroicon-o-play')
                ->color('warning')
                ->visible(fn (): bool => $this->getRecord()->status === TracingRequestStatus::Open)
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->runTransition(fn (TracingRequestService $service, TracingRequest $record) => $service->start($record));
                }),
            Action::make('recordResponse')
                ->label(fn (): string => blank(data_get($this->getRecord()->response_metadata, 'summary'))
                    ? 'Record response'
                    : 'Update response')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('info')
                ->visible(fn (): bool => $this->getRecord()->status !== TracingRequestStatus::Cancelled
                    && (
                        blank(data_get($this->getRecord()->response_metadata, 'summary'))
                        || in_array($this->getRecord()->status, [
                            TracingRequestStatus::Open,
                            TracingRequestStatus::InProgress,
                        ], true)
                    ))
                ->schema([
                    Textarea::make('summary')
                        ->label('Response summary')
                        ->required()
                        ->rows(4)
                        ->default(fn (): ?string => data_get($this->getRecord()->response_metadata, 'summary'))
                        ->helperText('What you told the requestor / regulator.'),
                    TextInput::make('evidence_reference')
                        ->label('Evidence reference')
                        ->maxLength(255)
                        ->default(fn (): ?string => data_get($this->getRecord()->response_metadata, 'evidence_reference'))
                        ->placeholder('Optional: EPCIS doc #, attachment ID, file name…'),
                    Textarea::make('evidence_notes')
                        ->label('Evidence notes')
                        ->rows(3)
                        ->default(fn (): ?string => data_get($this->getRecord()->response_metadata, 'evidence_notes'))
                        ->placeholder('Optional supporting notes for the audit trail.'),
                ])
                ->action(function (array $data): void {
                    /** @var TracingRequest $record */
                    $record = $this->getRecord();

                    $metadata = array_filter([
                        'summary' => trim((string) ($data['summary'] ?? '')),
                        'evidence_reference' => filled($data['evidence_reference'] ?? null)
                            ? trim((string) $data['evidence_reference'])
                            : null,
                        'evidence_notes' => filled($data['evidence_notes'] ?? null)
                            ? trim((string) $data['evidence_notes'])
                            : null,
                        'recorded_by' => auth()->id(),
                        'recorded_at' => now()->toIso8601String(),
                    ], fn (mixed $value): bool => $value !== null && $value !== '');

                    $updated = app(TracingSlaService::class)->markResponded($record, $metadata);
                    $this->record = $updated;

                    Notification::make()
                        ->title('Response recorded')
                        ->body($updated->sla_breached
                            ? 'Response saved. SLA was already breached when recorded.'
                            : 'Response saved against the SLA clock.')
                        ->success()
                        ->send();
                }),
            Action::make('complete')
                ->label('Mark completed')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->getRecord()->status === TracingRequestStatus::InProgress
                    && $this->getRecord()->responded_at !== null
                    && filled(data_get($this->getRecord()->response_metadata, 'summary')))
                ->requiresConfirmation()
                ->modalDescription('Complete only after the requestor response is recorded.')
                ->action(function (): void {
                    $this->runTransition(fn (TracingRequestService $service, TracingRequest $record) => $service->complete($record));
                }),
            Action::make('cancel')
                ->label('Cancel')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => in_array(
                    $this->getRecord()->status,
                    [TracingRequestStatus::Open, TracingRequestStatus::InProgress],
                    true,
                ))
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->runTransition(fn (TracingRequestService $service, TracingRequest $record) => $service->cancel($record));
                }),
        ];
    }

    /**
     * @param  callable(TracingRequestService, TracingRequest): TracingRequest  $callback
     */
    private function runTransition(callable $callback): void
    {
        /** @var TracingRequest $record */
        $record = $this->getRecord();

        try {
            $updated = $callback(app(TracingRequestService::class), $record);
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title('Status change failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->record = $updated;

        Notification::make()
            ->title('Tracing request updated')
            ->body('Status is now '.$updated->status->label().'.')
            ->success()
            ->send();
    }
}
