<?php

namespace App\Filament\App\Resources\Exceptions\Actions;

use App\Actions\Epcis\ReplaceEpcisDocumentPayload;
use App\Actions\Epcis\ReprocessEpcisDocument;
use App\Actions\Epcis\VoidEpcisDocument;
use App\Exceptions\DuplicateEpcisUploadException;
use App\Filament\App\Resources\Exceptions\Pages\ViewException;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionAction as ExceptionActionModel;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionRootCause;
use App\Models\User;
use App\Services\Exceptions\ExceptionService;
use App\Support\Exceptions\ExceptionCorrectionProfile;
use App\Support\Filesystem\SafeFilename;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Document-correction tools for exceptions raised by structural EPCIS validation
 * (see {@see ExceptionCorrectionProfile::FAMILY_DOCUMENT}). Mirrors ViewEpcisDocument's
 * upload/reprocess/void actions but operates on the exception's linked document and
 * refreshes the exception page (not the document page) afterward.
 */
final class CorrectDocumentActions
{
    public static function makeGroup(ViewException $page): ActionGroup
    {
        $profile = self::profileFor($page);

        return ActionGroup::make([
            self::uploadCorrectedFile($page),
            self::reprocessDocument($page),
            self::voidLinkedDocument($page),
        ])
            ->label('Fix or replace document')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color($profile->primaryActionKey() === ExceptionCorrectionProfile::ACTION_FIX_DOCUMENT ? 'primary' : 'gray')
            ->button()
            ->visible(fn (): bool => self::isGroupVisible($page));
    }

    public static function uploadCorrectedFile(ViewException $page): Action
    {
        $maxKb = max(1, (int) config('tracepharma.epcis.max_upload_kb', 20480));

        return RegulatoryCompliance::apply(
            Action::make('uploadCorrectedFile')
                ->label('Upload corrected file')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('primary')
                ->visible(function () use ($page): bool {
                    $document = self::document($page);

                    return $document !== null
                        && $document->status === 'error'
                        && TenantFeatures::forTenant(tenant())->supportsInboundIntegrations();
                })
                ->modalHeading('Upload corrected EPCIS file')
                ->modalDescription('Replace this errored document’s XML and re-process it in place. Prior events are superseded by a new ingest generation on success.')
                ->modalSubmitActionLabel('Upload and re-process')
                ->schema([
                    FileUpload::make('file')
                        ->label('Corrected EPCIS XML')
                        ->acceptedFileTypes([
                            'text/xml',
                            'application/xml',
                            'application/xhtml+xml',
                            'application/octet-stream',
                            'text/plain',
                        ])
                        ->rules([
                            'file',
                            'extensions:xml',
                            'max:'.$maxKb,
                        ])
                        ->maxSize($maxKb)
                        ->required()
                        ->storeFiles(false),
                    Textarea::make('notes')
                        ->label('Notes')
                        ->rows(2)
                        ->maxLength(5000)
                        ->nullable(),
                ])
                ->action(function (array $data) use ($page): void {
                    $document = self::document($page);
                    if ($document === null) {
                        self::noLinkedDocumentNotification();

                        return;
                    }

                    $file = $data['file'] ?? null;
                    if (is_array($file)) {
                        $file = $file[0] ?? null;
                    }

                    if (! $file instanceof TemporaryUploadedFile) {
                        Notification::make()
                            ->title('Upload failed')
                            ->body('No XML file was received.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $originalFilename = SafeFilename::forUpload($file->getClientOriginalName(), 'corrected.xml');
                    $storedRelative = 'epcis/uploads/'.(string) Str::uuid().'.xml';
                    Storage::disk('local')->put($storedRelative, $file->get());
                    $absolutePath = Storage::disk('local')->path($storedRelative);
                    $sync = Queue::getDefaultDriver() === 'sync';

                    try {
                        $document = app(ReplaceEpcisDocumentPayload::class)->handle($document, $absolutePath, [
                            'original_filename' => $originalFilename,
                            'notes' => filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
                            'sync' => $sync,
                        ]);
                    } catch (DuplicateEpcisUploadException $e) {
                        Notification::make()
                            ->title('Duplicate file')
                            ->body('This XML already exists as document #'.$e->existing->getKey().'.')
                            ->warning()
                            ->send();

                        return;
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Corrected upload failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $notes = filled($data['notes'] ?? null)
                        ? (string) $data['notes']
                        : 'Corrected EPCIS file uploaded and re-processed.';
                    self::resolveLinkedCaseAfterCorrection($page, $notes);

                    $page->refreshRecord();

                    Notification::make()
                        ->title($sync || in_array($document->status, ['parsed', 'validated', 'error'], true)
                            ? 'Corrected file processed'
                            : 'Corrected file queued')
                        ->body('Status: '.$document->status.' · Reprocess #'.(int) $document->reprocess_count)
                        ->success()
                        ->send();
                }),
            'exception_upload_corrected_file',
            requireReason: false,
        );
    }

    public static function reprocessDocument(ViewException $page): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('reprocessDocument')
                ->label('Re-process')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Re-process EPCIS document')
                ->modalDescription('The document will be queued for re-ingestion. Open or in-progress receiving sessions block reprocess unless forced.')
                ->visible(function () use ($page): bool {
                    $document = self::document($page);

                    return $document !== null
                        && in_array($document->status, ['parsed', 'validated', 'error', 'received'], true);
                })
                ->action(function () use ($page): void {
                    $document = self::document($page);
                    if ($document === null) {
                        self::noLinkedDocumentNotification();

                        return;
                    }

                    $sync = Queue::getDefaultDriver() === 'sync';

                    try {
                        $document = app(ReprocessEpcisDocument::class)->handle($document, $sync);
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Re-process failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    self::resolveLinkedCaseAfterCorrection(
                        $page,
                        'Linked EPCIS document re-processed from exception correction tools.',
                    );

                    $page->refreshRecord();

                    if ($sync || $document->status === 'parsed') {
                        Notification::make()
                            ->title('Re-process complete')
                            ->body('Status: '.$document->status.' · Reprocess #'.(int) $document->reprocess_count)
                            ->success()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Re-process queued')
                        ->body('Document will be processed in the background.')
                        ->success()
                        ->send();
                }),
            'exception_reprocess_document',
            requireReason: false,
        );
    }

    public static function voidLinkedDocument(ViewException $page): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('voidLinkedDocument')
                ->label('Void document')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->visible(function () use ($page): bool {
                    $document = self::document($page);

                    return $document !== null && $document->status === 'error';
                })
                ->requiresConfirmation()
                ->modalHeading('Void errored EPCIS document')
                ->modalDescription('Marks the linked document as voided. Payload and history are retained for audit; it will no longer be treated as an active ingest failure. This is not a hard delete.')
                ->modalSubmitActionLabel('Void')
                ->schema([
                    Textarea::make('reason')
                        ->label('Reason')
                        ->rows(2)
                        ->maxLength(2000)
                        ->required(),
                ])
                ->action(function (array $data) use ($page): void {
                    $document = self::document($page);
                    if ($document === null) {
                        self::noLinkedDocumentNotification();

                        return;
                    }

                    try {
                        $document = app(VoidEpcisDocument::class)->handle(
                            $document,
                            (string) $data['reason'],
                        );
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Void failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    self::resolveLinkedCaseAfterCorrection(
                        $page,
                        'Linked EPCIS document voided: '.(string) $data['reason'],
                    );

                    $page->refreshRecord();

                    Notification::make()
                        ->title('Document voided')
                        ->body('Document #'.$document->getKey().' is now voided.')
                        ->success()
                        ->send();
                }),
            'exception_void_document',
            requireReason: true,
            existingReasonField: 'reason',
        );
    }

    private static function resolveLinkedCaseAfterCorrection(ViewException $page, string $notes): void
    {
        /** @var ExceptionCase $record */
        $record = $page->getRecord();

        if ($record->status?->isOpen() !== true) {
            return;
        }

        /** @var User|null $actor */
        $actor = auth()->user();
        if ($actor === null) {
            return;
        }

        $profile = self::profileFor($page);
        $rootCauseId = ExceptionRootCause::query()
            ->where('code', $profile->suggestedRootCauseCode() ?? 'file_format_issue')
            ->value('id')
            ?? ExceptionRootCause::query()->where('code', 'file_format_issue')->value('id');
        $resolutionActionId = ExceptionActionModel::query()
            ->where('code', $profile->suggestedResolutionActionCode() ?? 'reprocess_document')
            ->value('id')
            ?? ExceptionActionModel::query()->where('code', 'reprocess_document')->value('id');

        if ($rootCauseId === null || $resolutionActionId === null) {
            Notification::make()
                ->title('Document corrected, but resolve failed')
                ->body('Resolution catalog is missing the expected root cause / action codes.')
                ->warning()
                ->send();

            return;
        }

        try {
            app(ExceptionService::class)->resolve(
                $record,
                $actor,
                (int) $rootCauseId,
                (int) $resolutionActionId,
                $notes,
            );
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Document corrected, but resolve failed')
                ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                ->warning()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('Document corrected, but resolve failed')
                ->body($e->getMessage())
                ->warning()
                ->send();
        }
    }

    private static function isGroupVisible(ViewException $page): bool
    {
        /** @var ExceptionCase $record */
        $record = $page->getRecord();

        return $record->status?->isOpen() === true
            && self::profileFor($page)->showsDocumentTools()
            && self::document($page) !== null;
    }

    private static function profileFor(ViewException $page): ExceptionCorrectionProfile
    {
        /** @var ExceptionCase $record */
        $record = $page->getRecord();

        return ExceptionCorrectionProfile::forCase($record);
    }

    private static function document(ViewException $page): ?EpcisDocument
    {
        /** @var ExceptionCase $record */
        $record = $page->getRecord();

        if ($record->document_id === null) {
            return null;
        }

        return $record->document ?? $record->document()->first();
    }

    private static function noLinkedDocumentNotification(): void
    {
        Notification::make()
            ->title('No linked document')
            ->body('This exception has no linked EPCIS document.')
            ->danger()
            ->send();
    }
}
