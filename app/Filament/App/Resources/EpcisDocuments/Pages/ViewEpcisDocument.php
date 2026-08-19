<?php

namespace App\Filament\App\Resources\EpcisDocuments\Pages;

use App\Actions\Epcis\DeleteEpcisDocument;
use App\Actions\Epcis\ReplaceEpcisDocumentPayload;
use App\Actions\Epcis\ReprocessEpcisDocument;
use App\Actions\Epcis\ResolveEpcFromScan;
use App\Actions\Epcis\VoidEpcisDocument;
use App\Exceptions\DuplicateEpcisUploadException;
use App\Filament\App\Resources\EpcisDocuments\Actions\StartReceivingAction;
use App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingSession;
use App\Models\User;
use App\Services\Dscsa\DscsaComplianceReportGenerator;
use App\Services\Dscsa\TransactionReportGenerator;
use App\Support\Epcis\EpcisDocumentXmlDownload;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Filesystem\SafeFilename;
use App\Support\Receiving\ReceiveLayout;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class ViewEpcisDocument extends ViewRecord
{
    protected static string $resource = EpcisDocumentResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->loadMissing([
            'tradingPartner',
            'shipFromSite',
            'shipToSite',
            'shipToPartner',
            'receivingSession',
        ]);
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Summary';
    }

    public function getHeading(): string|Htmlable|null
    {
        return '#'.$this->getRecord()->getKey();
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var EpcisDocument $record */
        $record = $this->getRecord();

        $status = $record->floorReceiveStatusLabel()
            ?? (filled($record->status) ? (string) $record->status : '—');
        $events = number_format((int) $record->event_count);
        $epcs = number_format((int) $record->epc_count);

        $suffix = '';
        if ($record->status === 'error') {
            $errorCount = $record->exceptions()
                ->where('status', 'open')
                ->whereIn('severity', ['error', 'critical'])
                ->count();
            if ($errorCount > 0) {
                $suffix = ' · Validation errors ('.$errorCount.')';
            }
        }

        return "{$status} · {$events} events · {$epcs} EPCs{$suffix}";
    }

    protected function getHeaderActions(): array
    {
        $maxKb = max(1, (int) config('tracepharma.epcis.max_upload_kb', 20480));

        return [
            Action::make('downloadXml')
                ->label('Download EPCIS')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => filled($this->getRecord()->payload_path))
                ->disabled(fn (): bool => ! EpcisDocumentXmlDownload::available($this->getRecord()))
                ->tooltip(fn (): ?string => EpcisDocumentXmlDownload::available($this->getRecord())
                    ? 'Download the stored EPCIS XML payload'
                    : 'XML payload is missing from storage')
                ->action(function () {
                    /** @var EpcisDocument $record */
                    $record = $this->getRecord();

                    if (! EpcisDocumentXmlDownload::available($record)) {
                        Notification::make()
                            ->title('XML file missing')
                            ->body('The payload path is recorded but the file is not on disk.')
                            ->danger()
                            ->send();

                        return null;
                    }

                    /** @var User|null $actor */
                    $actor = auth()->user();

                    activity()
                        ->performedOn($record)
                        ->causedBy($actor)
                        ->withProperties([
                            'filename' => EpcisDocumentXmlDownload::filename($record),
                            'payload_path' => $record->payload_path,
                        ])
                        ->log('Downloaded EPCIS XML');

                    return EpcisDocumentXmlDownload::response($record);
                }),
            StartReceivingAction::forView(
                document: fn (): EpcisDocument => $this->getRecord(),
                onOpened: function (ReceivingSession $session): void {
                    Notification::make()
                        ->title('Receiving session ready')
                        ->body('Session #'.$session->getKey().' · '.$session->expected_parent_count.' expected parents')
                        ->success()
                        ->send();

                    $this->redirect(StartReceivingAction::receivingUrl($session));
                },
            ),
            Action::make('viewReceivingSession')
                ->label('View receiving session')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(function (): bool {
                    $session = $this->getRecord()->receivingSession;

                    return $session !== null && $session->status !== 'cancelled';
                })
                ->url(function (): ?string {
                    $session = $this->getRecord()->receivingSession;

                    return $session !== null ? ReceiveLayout::sessionUrl($session) : null;
                }),
            ActionGroup::make([
                Action::make('downloadTransactionReport')
                    ->label('Track & Trace')
                    ->icon(Heroicon::OutlinedMap)
                    ->disabled(fn (): bool => ! in_array($this->getRecord()->status, ['parsed', 'validated'], true))
                    ->tooltip(fn (): ?string => in_array($this->getRecord()->status, ['parsed', 'validated'], true)
                        ? 'Download Transaction Report PDF (one page per lot)'
                        : 'Document must be parsed or validated before generating a Transaction Report')
                    ->action(function () {
                        /** @var EpcisDocument $record */
                        $record = $this->getRecord();
                        /** @var User|null $actor */
                        $actor = auth()->user();
                        $result = app(TransactionReportGenerator::class)->generate($record, $actor);

                        activity()
                            ->performedOn($record)
                            ->causedBy($actor)
                            ->withProperties([
                                'lots' => count($result['data']->pages),
                                'filename' => $result['filename'],
                            ])
                            ->log('Downloaded Transaction Report');

                        return response()->streamDownload(
                            static function () use ($result): void {
                                echo $result['binary'];
                            },
                            $result['filename'],
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),
                Action::make('downloadComplianceReport')
                    ->label('Serialized Track & Trace')
                    ->icon(Heroicon::OutlinedViewfinderCircle)
                    ->disabled(fn (): bool => ! in_array($this->getRecord()->status, ['parsed', 'validated'], true))
                    ->tooltip(fn (): ?string => in_array($this->getRecord()->status, ['parsed', 'validated'], true)
                        ? 'Download DSCSA Compliance Report PDF (serials by lot)'
                        : 'Document must be parsed or validated before generating a Compliance Report')
                    ->action(function () {
                        /** @var EpcisDocument $record */
                        $record = $this->getRecord();
                        /** @var User|null $actor */
                        $actor = auth()->user();
                        $result = app(DscsaComplianceReportGenerator::class)->generate($record, $actor);

                        activity()
                            ->performedOn($record)
                            ->causedBy($actor)
                            ->withProperties([
                                'lots' => count($result['data']->lots),
                                'serials' => $result['data']->serialCount,
                                'filename' => $result['filename'],
                            ])
                            ->log('Downloaded DSCSA Compliance Report');

                        return response()->streamDownload(
                            static function () use ($result): void {
                                echo $result['binary'];
                            },
                            $result['filename'],
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),
                Action::make('probeScan')
                    ->label('Probe scan')
                    ->icon(Heroicon::OutlinedQrCode)
                    ->visible(fn (): bool => ! $this->getRecord()->isFloorReceived())
                    ->modalHeading('Probe scan')
                    ->modalDescription('Resolve a warehouse or handheld scan against ingested EPCs.')
                    ->modalSubmitActionLabel('Resolve')
                    ->schema([
                        TextInput::make('scan')
                            ->label('Scan')
                            ->required()
                            ->autofocus()
                            ->maxLength(255),
                    ])
                    ->action(function (array $data): void {
                        $result = app(ResolveEpcFromScan::class)->handle((string) $data['scan']);
                        $epc = $result['epc'];

                        if ($epc === null) {
                            Notification::make()
                                ->title('EPC not found')
                                ->body('No matching EPC for that scan.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $identityBits = array_filter([
                            filled($epc->gtin14) ? 'GTIN '.$epc->gtin14 : null,
                            filled($epc->sscc18) ? 'SSCC '.$epc->sscc18 : null,
                            filled($epc->epc_type) ? (string) $epc->epc_type : null,
                        ]);

                        Notification::make()
                            ->title('EPC resolved')
                            ->body(implode(' · ', array_filter([
                                $epc->epc_uri,
                                implode(' · ', $identityBits),
                            ])))
                            ->success()
                            ->send();

                        if (filled($result['ilmd_soft_mismatch'])) {
                            Notification::make()
                                ->title('ILMD soft mismatch')
                                ->body('Scan lot/expiry differs from stored ILMD for this EPC.')
                                ->warning()
                                ->send();
                        }
                    }),
                RegulatoryCompliance::apply(
                    Action::make('reprocess')
                        ->label('Re-process')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Re-process EPCIS document')
                        ->modalDescription('The document will be queued for re-ingestion. Open or in-progress receiving sessions block reprocess unless forced. On success, a new ingest generation replaces the active projection and superseded generations are removed; the stored XML payload is retained.')
                        ->visible(fn (): bool => JobRoleAccess::allowsAny(
                            Permissions::NavExceptions,
                            Permissions::NavIntegrations,
                        )
                            && ! $this->getRecord()->isFloorReceived()
                            && in_array($this->getRecord()->status, ['parsed', 'validated', 'error', 'received'], true))
                        ->action(function (): void {
                            /** @var EpcisDocument $document */
                            $document = $this->getRecord();
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

                            $this->refreshFormData(['status', 'event_count', 'epc_count', 'reprocess_count', 'error_message', 'last_processed_at', 'processed_at']);

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
                    'epcis_reprocess',
                    requireReason: false,
                ),
                RegulatoryCompliance::apply(
                    Action::make('uploadCorrectedFile')
                        ->label('Upload corrected file')
                        ->icon(Heroicon::OutlinedArrowUpTray)
                        ->visible(fn (): bool => $this->getRecord()->status === 'error'
                            && TenantFeatures::forTenant(tenant())->supportsInboundIntegrations())
                        ->modalHeading('Upload corrected EPCIS file')
                        ->modalDescription('Replace this errored document’s XML and re-process it in place. On success, a new ingest generation replaces the active projection and superseded generations are removed; the stored XML payload is retained.')
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
                        ->action(function (array $data): void {
                            /** @var EpcisDocument $document */
                            $document = $this->getRecord();

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

                            $this->refreshFormData([
                                'status',
                                'event_count',
                                'epc_count',
                                'reprocess_count',
                                'error_message',
                                'original_filename',
                                'last_processed_at',
                                'processed_at',
                                'notes',
                            ]);

                            Notification::make()
                                ->title($sync || in_array($document->status, ['parsed', 'validated', 'error'], true)
                                    ? 'Corrected file processed'
                                    : 'Corrected file queued')
                                ->body('Status: '.$document->status.' · Reprocess #'.(int) $document->reprocess_count)
                                ->success()
                                ->send();
                        }),
                    'epcis_upload_corrected',
                    requireReason: false,
                ),
                RegulatoryCompliance::apply(
                    Action::make('voidDocument')
                        ->label('Void document')
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->color('danger')
                        ->visible(fn (): bool => $this->getRecord()->status === 'error')
                        ->requiresConfirmation()
                        ->modalHeading('Void errored EPCIS document')
                        ->modalDescription('Marks this document as voided. Payload and history are retained for audit; it will no longer be treated as an active ingest failure. This is not a hard delete.')
                        ->modalSubmitActionLabel('Void')
                        ->schema([
                            Textarea::make('reason')
                                ->label('Reason')
                                ->rows(2)
                                ->maxLength(2000)
                                ->required(),
                        ])
                        ->action(function (array $data): void {
                            /** @var EpcisDocument $document */
                            $document = $this->getRecord();

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

                            $this->refreshFormData(['status', 'error_message', 'notes']);

                            Notification::make()
                                ->title('Document voided')
                                ->body('Document #'.$document->getKey().' is now voided.')
                                ->success()
                                ->send();
                        }),
                    'epcis_void',
                    requireReason: true,
                    existingReasonField: 'reason',
                ),
                RegulatoryCompliance::apply(
                    Action::make('deleteDocumentAndData')
                        ->label('Delete document and data')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->visible(fn (): bool => JobRoleAccess::allows(Permissions::NavExceptions)
                            && in_array($this->getRecord()->status, ['error', 'voided'], true)
                            && TenantFeatures::forTenant(tenant())->supportsInboundIntegrations())
                        ->disabled(fn (): bool => $this->hasActiveReceivingSession())
                        ->tooltip(fn (): ?string => $this->hasActiveReceivingSession()
                            ? 'Blocked by an open or in-progress receiving session.'
                            : null)
                        ->requiresConfirmation()
                        ->modalHeading('Delete document and all ingested data')
                        ->modalDescription('Permanently removes this document, events, EPC links for this file, exceptions, quarantine holds tied to it, and the stored payload. Shared EPC master records are kept. This cannot be undone. Distinct from Void, which retains audit history.')
                        ->modalSubmitActionLabel('Delete permanently')
                        ->schema([
                            Textarea::make('reason')
                                ->label('Reason')
                                ->rows(3)
                                ->maxLength(2000)
                                ->required(),
                        ])
                        ->action(function (array $data): void {
                            /** @var EpcisDocument $document */
                            $document = $this->getRecord();
                            $documentId = $document->getKey();

                            try {
                                app(DeleteEpcisDocument::class)->handle(
                                    $document,
                                    (string) $data['reason'],
                                );
                            } catch (Throwable $e) {
                                Notification::make()
                                    ->title('Delete failed')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title('Document deleted')
                                ->body('Document #'.$documentId.' and its ingested data were removed.')
                                ->success()
                                ->send();

                            $this->redirect(EpcisDocumentResource::getUrl('index'));
                        }),
                    'epcis_delete_document',
                    requireReason: true,
                    existingReasonField: 'reason',
                ),
            ])
                ->label('More actions')
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->button()
                ->color('gray'),
        ];
    }

    private function hasActiveReceivingSession(): bool
    {
        if (! Schema::hasTable('receiving_sessions')) {
            return false;
        }

        return ReceivingSession::query()
            ->where('epcis_document_id', $this->getRecord()->getKey())
            ->whereIn('status', ['open', 'in_progress'])
            ->exists();
    }
}
