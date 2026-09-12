<?php

declare(strict_types=1);

namespace App\Filament\App\Support;

use App\Actions\Exports\QueueTrackTraceExport;
use App\Filament\Notifications\Notification;
use App\Models\DataExport;
use App\Models\Epcis\EpcisDocument;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class QueueSerializedTrackTraceExport
{
    public static function forDocument(EpcisDocument $document, ?User $actor): ?DataExport
    {
        if ($actor === null) {
            Notification::make()
                ->title('Sign in required')
                ->body('You must be signed in to export a Serialized Track & Trace report.')
                ->danger()
                ->send();

            return null;
        }

        try {
            $export = app(QueueTrackTraceExport::class)->handle($actor, [
                'document_id' => (int) $document->getKey(),
            ]);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first()
                ?? 'This document cannot be exported.';

            Notification::make()
                ->title('Export could not be queued')
                ->body((string) $message)
                ->danger()
                ->send();

            return null;
        }

        activity()
            ->performedOn($document)
            ->causedBy($actor)
            ->withProperties([
                'export_id' => $export->getKey(),
                'document_id' => (int) $document->getKey(),
            ])
            ->log('Queued Serialized Track & Trace export');

        Notification::make()
            ->title('Export queued')
            ->body('Your DSCSA Compliance Report PDF is generating. You will receive a notification when it is ready to download.')
            ->success()
            ->send();

        return $export;
    }
}
