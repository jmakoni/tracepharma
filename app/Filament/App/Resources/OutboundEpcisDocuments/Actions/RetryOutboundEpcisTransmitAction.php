<?php

namespace App\Filament\App\Resources\OutboundEpcisDocuments\Actions;

use App\Actions\Epcis\PrepareOutboundEpcisForRetransmit;
use App\Actions\EpcisJobs\EnqueueEpcisJob;
use App\Actions\EpcisJobs\RequeueEpcisJob;
use App\Enums\EpcisJobStatus;
use App\Models\Epcis\EpcisDocument;
use App\Models\EpcisJob;
use App\Models\User;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use Filament\Actions\Action;
use App\Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Shared Retry outbound EPCIS transmit action for the view page and table row.
 *
 * Always remints InstanceIdentifier + filename (shipping: full TI rebuild from
 * current open hierarchy) before enqueue/requeue/sync transmit.
 */
final class RetryOutboundEpcisTransmitAction
{
    public static function visible(EpcisDocument $record): bool
    {
        return $record->direction === 'outbound'
            && in_array($record->transmission_status, ['failed', 'skipped'], true)
            && filled($record->payload_path);
    }

    /**
     * Prepare (rebuild/remint + GS1 validate), then requeue/enqueue or sync-transmit.
     */
    public static function retry(EpcisDocument $document): EpcisDocument
    {
        try {
            $prepared = app(PrepareOutboundEpcisForRetransmit::class)->handle($document);
            $document = $prepared['document'];
        } catch (Throwable $e) {
            $document->refresh();
            Notification::make()
                ->title('Retransmit prepare failed')
                ->body($e->getMessage() ?: 'Could not rebuild or remint the outbound EPCIS payload.')
                ->danger()
                ->send();

            return $document;
        }

        if (config('tracepharma.epcis_jobs.enabled')) {
            $requeueable = EpcisJob::query()
                ->where('epcis_document_id', $document->getKey())
                ->whereNull('archived_at')
                ->whereIn('status', [
                    EpcisJobStatus::Error->value,
                    EpcisJobStatus::Cancelled->value,
                ])
                ->latest('id')
                ->first();

            if ($requeueable !== null) {
                // Payload already prepared above; skip double prepare in RebuildEpcisJobPayload.
                app(RequeueEpcisJob::class)->handle($requeueable, auth()->id(), skipPayloadPrepare: true);
            } else {
                app(EnqueueEpcisJob::class)->handle($document, auth()->id());
            }

            $document->refresh();

            /** @var User|null $actor */
            $actor = auth()->user();

            activity()
                ->performedOn($document)
                ->causedBy($actor)
                ->withProperties([
                    'transmission_status' => $document->transmission_status,
                    'error_message' => $document->error_message,
                    'via' => 'epcis_job',
                    'prepare_mode' => $prepared['mode'],
                    'document_uuid' => $prepared['new_uuid'],
                    'original_filename' => $prepared['new_filename'],
                    'previous_document_uuid' => $prepared['old_uuid'],
                    'previous_original_filename' => $prepared['old_filename'],
                ])
                ->log('Retried outbound EPCIS transmission');

            self::notify($document);

            return $document;
        }

        app(OutboundEpcisTransmitter::class)->transmit($document);

        $document->refresh();

        /** @var User|null $actor */
        $actor = auth()->user();

        activity()
            ->performedOn($document)
            ->causedBy($actor)
            ->withProperties([
                'transmission_status' => $document->transmission_status,
                'error_message' => $document->error_message,
                'prepare_mode' => $prepared['mode'],
                'document_uuid' => $prepared['new_uuid'],
                'original_filename' => $prepared['new_filename'],
                'previous_document_uuid' => $prepared['old_uuid'],
                'previous_original_filename' => $prepared['old_filename'],
            ])
            ->log('Retried outbound EPCIS transmission');

        self::notify($document);

        return $document;
    }

    private static function notify(EpcisDocument $document): void
    {
        match ($document->transmission_status) {
            'sent' => Notification::make()
                ->title('EPCIS transmitted')
                ->body('The document was sent successfully.')
                ->success()
                ->send(),
            'queued' => Notification::make()
                ->title('EPCIS job queued')
                ->body('The document was queued for outbound transmission.')
                ->success()
                ->send(),
            'failed' => Notification::make()
                ->title('Transmission failed')
                ->body($document->error_message ?: 'The outbound transmission failed.')
                ->danger()
                ->send(),
            'skipped' => Notification::make()
                ->title('Transmission skipped')
                ->body('No outbound connection is configured, or the payload is missing.')
                ->warning()
                ->send(),
            default => Notification::make()
                ->title('Transmission status: '.((string) ($document->transmission_status ?: '—')))
                ->send(),
        };
    }

    /**
     * View-page header action: Retry transmit.
     *
     * @param  callable(): EpcisDocument  $document
     */
    public static function forView(callable $document): Action
    {
        return Action::make('retryTransmit')
            ->label('Retry transmit')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(
                'Mints a new InstanceIdentifier and a prepare-time filename. For shipping documents, rebuilds TI from the current open hierarchy (packing children filtered after unpack; ship eventTime unchanged). Replaces the stored / client-portal file, validates GS1 EPCIS 1.2 / GS1 US R1.3, then transmits again.',
            )
            ->visible(fn (): bool => self::visible($document()))
            ->action(function () use ($document): void {
                self::retry($document());
            });
    }

    /**
     * Table row action: Retry transmit.
     */
    public static function forTable(): Action
    {
        return Action::make('retryTransmit')
            ->label('Retry transmit')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(
                'Mints a new InstanceIdentifier and a prepare-time filename. For shipping documents, rebuilds TI from the current open hierarchy (packing children filtered after unpack; ship eventTime unchanged). Replaces the stored / client-portal file, validates GS1 EPCIS 1.2 / GS1 US R1.3, then transmits again.',
            )
            ->visible(fn (EpcisDocument $record): bool => self::visible($record))
            ->action(fn (EpcisDocument $record) => self::retry($record));
    }
}
