<?php

namespace App\Filament\App\Resources\EpcisDocuments\Pages;

use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Actions\Epcis\SearchEpcisSchema;
use App\Enums\EpcisReceivedVia;
use App\Exceptions\DuplicateEpcisUploadException;
use App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource;
use App\Filament\App\Support\EpcisSchemaSearchForm;
use App\Filament\Notifications\Notification;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Quarantine\QuarantineHold;
use App\Models\TradingPartner;
use App\Models\User;
use App\Services\Quarantine\QuarantineService;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Filesystem\SafeFilename;
use App\Support\TenantFeatures;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ListEpcisDocuments extends ListRecords
{
    protected static string $resource = EpcisDocumentResource::class;

    /**
     * Last Find / Recall form state (simple or advanced) for Edit search.
     *
     * @var array<string, mixed>|null
     */
    public ?array $findRecallFormState = null;

    /**
     * @var array{type: string, total: int, truncated: bool, ids: list<int>, rows: list<array<string, mixed>>}|null
     */
    public ?array $schemaSearchPayload = null;

    /** @var list<int|string> */
    public array $selectedEpcIds = [];

    public ?int $quarantineEpcId = null;

    /** @var array<int, int|null> */
    private array $shipToSiteByEpcId = [];

    public function mount(): void
    {
        parent::mount();

        // Header actions are cached after mount(); mountAction() here silently no-ops.
        // Set defaultAction so the page wire:init mounts Find / Recall after boot.
        if (
            request()->boolean('findRecall')
            && TenantFeatures::forTenant(tenant())->supportsInboundIntegrations()
        ) {
            $this->defaultAction = 'findRecall';
        }
    }

    /**
     * Hidden from the header on purpose — mounted via replaceMountedAction after Search.
     * Must NOT use visible(false): Filament treats hidden actions as disabled and unmounts them.
     */
    public function findRecallResultsAction(): Action
    {
        return Action::make('findRecallResults')
            ->label('Search results')
            ->modalHeading('Find / Recall results')
            ->modalWidth('5xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (): View => view('filament.app.epcis.schema-search-results', [
                'type' => $this->schemaSearchPayload['type'] ?? 'epcs',
                'total' => $this->schemaSearchPayload['total'] ?? 0,
                'truncated' => $this->schemaSearchPayload['truncated'] ?? false,
                'rows' => $this->schemaSearchPayload['rows'] ?? [],
            ]))
            ->extraModalFooterActions([
                Action::make('editSearch')
                    ->label('Edit search')
                    ->color('gray')
                    ->action(function (): void {
                        $this->replaceMountedAction('findRecall');
                    }),
                Action::make('exportCsv')
                    ->label('Export CSV')
                    ->color('gray')
                    ->action(fn (): ?StreamedResponse => $this->exportSchemaSearchCsv()),
                Action::make('selectAllMatchingEpcs')
                    ->label(fn (): string => 'Select all '.number_format($this->schemaSearchPayload['total'] ?? 0).' matches')
                    ->color('gray')
                    ->visible(fn (): bool => $this->canSelectAllMatchingEpcs())
                    ->requiresConfirmation()
                    ->modalHeading('Select all matches?')
                    ->modalDescription(function (): string {
                        $total = (int) ($this->schemaSearchPayload['total'] ?? 0);
                        $displayed = count($this->schemaSearchPayload['rows'] ?? []);

                        return "This selects all {$total} matching serialized units, including "
                            .($total - $displayed).' not shown in the table. Use Quarantine selected only when you intend to act on the full result set.';
                    })
                    ->modalSubmitActionLabel('Select all matches')
                    ->action(function (): void {
                        $this->selectAllMatchingEpcs();
                    }),
                RegulatoryCompliance::apply(
                    Action::make('quarantineSelected')
                        ->label('Quarantine selected')
                        ->color('warning')
                        ->visible(fn (): bool => ($this->schemaSearchPayload['type'] ?? null) === 'epcs'
                            && $this->canQuarantineFromFindRecall()
                            && ! $this->isFindRecallResultTruncated())
                        ->modalHeading('Quarantine selected units')
                        ->modalSubmitActionLabel('Open holds')
                        ->schema([
                            Textarea::make('reason')
                                ->label('Reason')
                                ->required()
                                ->rows(3)
                                ->default('Opened from Find / Recall')
                                ->maxLength(2000),
                        ])
                        ->action(function (array $data): void {
                            $this->quarantineSelectedEpcs(
                                reason: (string) $data['reason'],
                            );
                        }),
                    'epcis_quarantine_selected',
                    requireReason: true,
                    existingReasonField: 'reason',
                ),
            ]);
    }

    public function confirmQuarantineEpcAction(): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('confirmQuarantineEpc')
                ->label('Confirm quarantine')
                ->modalHeading('Quarantine unit')
                ->modalSubmitActionLabel('Open hold')
                ->fillForm(fn (): array => [
                    'epc_id' => $this->quarantineEpcId,
                    'reason' => 'Opened from Find / Recall',
                ])
                ->schema([
                    Hidden::make('epc_id')->required(),
                    Textarea::make('reason')
                        ->label('Reason')
                        ->required()
                        ->rows(3)
                        ->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    $epcId = (int) ($data['epc_id'] ?? $this->quarantineEpcId ?? 0);
                    $this->openQuarantineHoldForEpc(
                        epcId: $epcId,
                        reason: (string) ($data['reason'] ?? 'Opened from Find / Recall'),
                    );
                }),
            'epcis_quarantine_epc',
            requireReason: true,
            existingReasonField: 'reason',
        );
    }

    protected function getHeaderActions(): array
    {
        $maxKb = (int) config('tracepharma.epcis.max_upload_kb', 81920);

        return [
            Action::make('findRecall')
                ->label('Find / Recall')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->visible(fn (): bool => TenantFeatures::forTenant(tenant())->supportsInboundIntegrations())
                ->modalHeading('Find / Recall')
                ->modalDescription('Filter the inbound file list by GTIN, lot, or ASN/PO. Advanced can still open unit results.')
                ->modalSubmitActionLabel('Search')
                ->modalWidth('5xl')
                ->fillForm(fn (): array => $this->findRecallFormState ?? [
                    'advanced' => false,
                    'gtin14' => null,
                    'lot_number' => null,
                    'asn_or_po' => null,
                    'more_fields' => false,
                    'result_type' => 'documents',
                    'rules' => EpcisSchemaSearchForm::defaultRules('documents'),
                ])
                ->schema(EpcisSchemaSearchForm::schema())
                ->extraModalFooterActions([
                    Action::make('resetFindRecall')
                        ->label('Reset')
                        ->color('gray')
                        ->action(function (): void {
                            $this->resetFindRecallState();
                            $this->replaceMountedAction('findRecall');
                        }),
                ])
                ->action(function (array $data): void {
                    $this->findRecallFormState = $data;
                    $this->selectedEpcIds = [];

                    $payload = EpcisSchemaSearchForm::searchPayloadFromForm($data);

                    try {
                        $result = app(SearchEpcisSchema::class)->handle(
                            $payload['result_type'],
                            $payload['rules'],
                            actor: $this->findRecallActor(),
                        );
                    } catch (DomainException|InvalidArgumentException $e) {
                        Notification::make()
                            ->title('Search not run')
                            ->body($e->getMessage())
                            ->warning()
                            ->send();

                        return;
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Search failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    if ($result['total'] === 0) {
                        Notification::make()
                            ->title('No matches')
                            ->body($this->noMatchesBodyForRules($payload['rules']))
                            ->warning()
                            ->send();

                        return;
                    }

                    // Document hits filter the inbound table (no results modal).
                    if (($result['type'] ?? null) === 'documents') {
                        $this->applyInboundListFiltersFromRules($payload['rules']);

                        Notification::make()
                            ->title('Inbound list updated')
                            ->body('Showing '.$result['total'].' matching file'.($result['total'] === 1 ? '' : 's').'.')
                            ->success()
                            ->send();

                        return;
                    }

                    try {
                        $mappedRows = $this->mapSearchRows($result['type'], $result['rows']);

                        $this->schemaSearchPayload = [
                            'type' => $result['type'],
                            'total' => $result['total'],
                            'truncated' => $result['truncated'],
                            'ids' => $result['ids'] ?? [],
                            'rows' => $mappedRows,
                        ];

                        $this->replaceMountedAction('findRecallResults');
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Search failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            RegulatoryCompliance::apply(
                Action::make('uploadEpcis')
                    ->label('Upload EPCIS')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->visible(fn (): bool => TenantFeatures::forTenant(tenant())->supportsInboundIntegrations())
                    ->modalHeading('Upload EPCIS')
                    ->modalSubmitActionLabel('Upload')
                    ->schema([
                        FileUpload::make('file')
                            ->label('EPCIS XML')
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
                        Select::make('trading_partner_id')
                            ->label('Trading partner')
                            ->options(fn (): array => TradingPartner::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->nullable(),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3)
                            ->maxLength(5000)
                            ->nullable(),
                    ])
                    ->action(function (array $data): void {
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

                        $originalFilename = SafeFilename::forUpload($file->getClientOriginalName(), 'upload.xml');
                        $storedRelative = 'epcis/uploads/'.(string) Str::uuid().'.xml';
                        Storage::disk('local')->put($storedRelative, $file->get());
                        $absolutePath = Storage::disk('local')->path($storedRelative);

                        $sync = Queue::getDefaultDriver() === 'sync';

                        try {
                            /** @var EpcisDocument $document */
                            $document = app(ReceiveEpcisUpload::class)->handle($absolutePath, [
                                'direction' => 'inbound',
                                'received_via' => EpcisReceivedVia::FilamentUpload,
                                'original_filename' => $originalFilename,
                                'trading_partner_id' => isset($data['trading_partner_id'])
                                    ? (int) $data['trading_partner_id']
                                    : null,
                                'notes' => filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
                                'dispatch' => true,
                                'sync' => $sync,
                            ]);
                        } catch (DuplicateEpcisUploadException $e) {
                            Notification::make()
                                ->title('Duplicate upload')
                                ->body('This file was already received (document #'.$e->existing->getKey().').')
                                ->warning()
                                ->send();

                            return;
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Upload failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($sync || $document->status === 'parsed') {
                            Notification::make()
                                ->title('Ingest complete')
                                ->body(
                                    'Events: '.(int) $document->event_count
                                    .' · EPCs: '.(int) $document->epc_count
                                    .' · Status: '.$document->status
                                )
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Upload received — processing queued')
                            ->body($originalFilename)
                            ->success()
                            ->send();
                    }),
                'epcis_upload',
                requireReason: false,
            ),
        ];
    }

    public function openQuarantineFromSearch(int $epcId): void
    {
        if (! $this->canQuarantineFromFindRecall()) {
            Notification::make()
                ->title('Not authorized')
                ->body('Quarantine actions require the Exceptions job role.')
                ->danger()
                ->send();

            return;
        }

        if (! $this->canQuarantineEpc($epcId)) {
            Notification::make()
                ->title('Not authorized')
                ->body('You do not have access to quarantine units for this site.')
                ->danger()
                ->send();

            return;
        }

        $this->quarantineEpcId = $epcId;
        $this->replaceMountedAction('confirmQuarantineEpc');
    }

    private function findRecallActor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public function canQuarantineFromFindRecall(): bool
    {
        return JobRoleAccess::allows(Permissions::NavExceptions);
    }

    public function isFindRecallResultTruncated(): bool
    {
        return (bool) ($this->schemaSearchPayload['truncated'] ?? false);
    }

    public function canQuarantineEpc(int $epcId): bool
    {
        if (! $this->canQuarantineFromFindRecall()) {
            return false;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        return SiteAccess::canAccessShipToSite($user, $this->shipToSiteIdForEpc($epcId));
    }

    public function viewUnitsFromDocument(int $documentId): void
    {
        if (! EpcisDocumentResource::getEloquentQuery()->whereKey($documentId)->exists()) {
            Notification::make()
                ->title('Not authorized')
                ->body('You do not have access to this inbound document.')
                ->danger()
                ->send();

            return;
        }

        $this->selectedEpcIds = [];
        $this->findRecallFormState = [
            'advanced' => true,
            'more_fields' => true,
            'gtin14' => null,
            'lot_number' => null,
            'result_type' => 'epcs',
            'rules' => [
                [
                    'field' => 'doc.id',
                    'operator' => 'eq',
                    'value' => $documentId,
                ],
            ],
        ];

        try {
            $result = app(SearchEpcisSchema::class)->handle(
                'epcs',
                [
                    [
                        'field' => 'doc.id',
                        'operator' => 'eq',
                        'value' => $documentId,
                    ],
                ],
                actor: $this->findRecallActor(),
            );
        } catch (DomainException|InvalidArgumentException $e) {
            Notification::make()
                ->title('Search not run')
                ->body($e->getMessage())
                ->warning()
                ->send();

            return;
        } catch (Throwable $e) {
            Notification::make()
                ->title('Search failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($result['total'] === 0) {
            Notification::make()
                ->title('No matches')
                ->body('No serialized units found for document #'.$documentId.'.')
                ->warning()
                ->send();

            return;
        }

        $this->schemaSearchPayload = [
            'type' => $result['type'],
            'total' => $result['total'],
            'truncated' => $result['truncated'],
            'ids' => $result['ids'] ?? [],
            'rows' => $this->mapSearchRows($result['type'], $result['rows']),
        ];

        $this->replaceMountedAction('findRecallResults');
    }

    public function toggleSelectAllEpcs(bool $checked): void
    {
        if (($this->schemaSearchPayload['type'] ?? null) !== 'epcs') {
            return;
        }

        if (! $checked) {
            $this->selectedEpcIds = [];

            return;
        }

        $this->selectedEpcIds = $this->displayedEpcRowIds();
    }

    public function selectAllMatchingEpcs(): void
    {
        if (($this->schemaSearchPayload['type'] ?? null) !== 'epcs') {
            return;
        }

        if ($this->isFindRecallResultTruncated()) {
            Notification::make()
                ->title('Select all unavailable')
                ->body('Results are capped at 1,000 matches. Refine your search before selecting or quarantining the full set.')
                ->warning()
                ->send();

            return;
        }

        $this->selectedEpcIds = collect($this->schemaSearchPayload['ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    public function allDisplayedEpcsSelected(): bool
    {
        $displayed = $this->displayedEpcRowIds();

        if ($displayed === []) {
            return false;
        }

        $selected = collect($this->selectedEpcIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        return count(array_intersect($displayed, $selected)) === count($displayed);
    }

    public function canSelectAllMatchingEpcs(): bool
    {
        if (($this->schemaSearchPayload['type'] ?? null) !== 'epcs') {
            return false;
        }

        if ($this->isFindRecallResultTruncated()) {
            return false;
        }

        $total = (int) ($this->schemaSearchPayload['total'] ?? 0);
        $displayed = count($this->schemaSearchPayload['rows'] ?? []);

        if ($total <= $displayed || $displayed === 0) {
            return false;
        }

        $fullIds = collect($this->schemaSearchPayload['ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($fullIds === []) {
            return false;
        }

        $selected = collect($this->selectedEpcIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        return count(array_intersect($fullIds, $selected)) < count($fullIds);
    }

    /**
     * @return list<int>
     */
    private function displayedEpcRowIds(): array
    {
        return collect($this->schemaSearchPayload['rows'] ?? [])
            ->map(fn (array $row): int => (int) ($row['id'] ?? 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    private function openQuarantineHoldForEpc(int $epcId, string $reason): void
    {
        if (! $this->canQuarantineFromFindRecall()) {
            Notification::make()
                ->title('Not authorized')
                ->body('Quarantine actions require the Exceptions job role.')
                ->danger()
                ->send();

            return;
        }

        $epc = Epc::query()->find($epcId);
        if ($epc === null) {
            Notification::make()->title('EPC not found')->danger()->send();

            return;
        }

        if (! $this->canQuarantineEpc($epcId)) {
            Notification::make()
                ->title('Not authorized')
                ->body('You do not have access to quarantine units for this site.')
                ->danger()
                ->send();

            return;
        }

        $documentId = $this->latestDocumentIdForEpc($epcId);
        $document = $documentId !== null ? EpcisDocument::query()->find($documentId) : null;

        $case = app(QuarantineService::class)->quarantineFromFindRecall(
            [$epcId],
            $reason,
            auth()->user(),
            $document,
        );

        Notification::make()
            ->title('Quarantine case opened')
            ->body('Case #'.$case->getKey().' · '.($epc->epc_uri ?? 'EPC #'.$epcId))
            ->success()
            ->send();
    }

    private function quarantineSelectedEpcs(string $reason): void
    {
        if (! $this->canQuarantineFromFindRecall()) {
            Notification::make()
                ->title('Not authorized')
                ->body('Quarantine actions require the Exceptions job role.')
                ->danger()
                ->send();

            return;
        }

        if ($this->isFindRecallResultTruncated()) {
            Notification::make()
                ->title('Bulk quarantine unavailable')
                ->body('Results are capped at 1,000 matches. Refine your search before quarantining the full set.')
                ->warning()
                ->send();

            return;
        }

        $ids = collect($this->selectedEpcIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            Notification::make()
                ->title('Nothing selected')
                ->body('Select one or more units to quarantine.')
                ->warning()
                ->send();

            return;
        }

        $this->shipToSiteByEpcId = $this->batchShipToSiteIdsForEpcs(
            $this->batchDocumentIdsForEpcs($ids),
        );

        $deniedSiteIds = collect($ids)
            ->filter(fn (int $epcId): bool => ! $this->canQuarantineEpc($epcId))
            ->values()
            ->all();

        if ($deniedSiteIds !== []) {
            Notification::make()
                ->title('Not authorized')
                ->body('You do not have access to quarantine one or more selected units for their site.')
                ->danger()
                ->send();

            return;
        }

        $document = null;
        $documentIdsByEpc = $this->batchDocumentIdsForEpcs($ids);
        if ($documentIdsByEpc !== []) {
            $firstDocumentId = reset($documentIdsByEpc);
            if ($firstDocumentId !== false) {
                $document = EpcisDocument::query()->find((int) $firstDocumentId);
            }
        }

        $case = app(QuarantineService::class)->quarantineFromFindRecall(
            $ids,
            $reason,
            auth()->user(),
            $document,
        );

        $openHolds = QuarantineHold::query()
            ->open()
            ->where('exception_id', $case->getKey())
            ->count();

        Notification::make()
            ->title('Quarantine case opened')
            ->body('Case #'.$case->getKey()." · {$openHolds} unit(s) on hold.")
            ->success()
            ->send();
    }

    private function shipToSiteIdForEpc(int $epcId): ?int
    {
        if (array_key_exists($epcId, $this->shipToSiteByEpcId)) {
            return $this->shipToSiteByEpcId[$epcId];
        }

        $documentId = $this->latestDocumentIdForEpc($epcId);
        if ($documentId === null) {
            return null;
        }

        $shipToSiteId = EpcisDocument::query()
            ->whereKey($documentId)
            ->value('ship_to_site_id');

        return $shipToSiteId !== null ? (int) $shipToSiteId : null;
    }

    private function latestDocumentIdForEpc(int $epcId): ?int
    {
        if (! Schema::hasTable('document_epcs')) {
            return null;
        }

        $query = DB::table('document_epcs')
            ->where('document_epcs.epc_id', $epcId);

        $this->scopeActiveDocumentEpcLinks($query);
        $this->orderLatestDocumentForEpcLink($query);

        $documentId = (int) ($query->value('document_epcs.document_id') ?? 0);

        return $documentId > 0 ? $documentId : null;
    }

    private function exportSchemaSearchCsv(): ?StreamedResponse
    {
        $payload = $this->schemaSearchPayload;
        if ($payload === null) {
            Notification::make()
                ->title('Nothing to export')
                ->warning()
                ->send();

            return null;
        }

        $formState = $this->findRecallFormState;
        if ($formState === null && ($payload['rows'] ?? []) === []) {
            Notification::make()
                ->title('Nothing to export')
                ->warning()
                ->send();

            return null;
        }

        $cachedIds = collect($payload['ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($cachedIds !== [] && ! ($payload['truncated'] ?? false) && ($payload['rows'] ?? []) !== []) {
            $type = (string) ($payload['type'] ?? 'epcs');
            $rows = $payload['rows'];
            $exportedCount = count($rows);
            $trueTotal = (int) ($payload['total'] ?? $exportedCount);
            $truncated = false;
            $filename = 'find-recall-'.$type.'-'.now()->format('Ymd-His').'.csv';

            return $this->streamFindRecallCsv($type, $rows, $exportedCount, $trueTotal, $truncated, $filename);
        }

        if ($formState === null) {
            Notification::make()
                ->title('Nothing to export')
                ->warning()
                ->send();

            return null;
        }

        $searchPayload = EpcisSchemaSearchForm::searchPayloadFromForm($formState);

        try {
            $result = app(SearchEpcisSchema::class)->handle(
                $searchPayload['result_type'],
                $searchPayload['rules'],
                displayLimit: 1000,
                actor: $this->findRecallActor(),
            );
        } catch (DomainException|InvalidArgumentException $e) {
            Notification::make()
                ->title('Export failed')
                ->body($e->getMessage())
                ->warning()
                ->send();

            return null;
        } catch (Throwable $e) {
            Notification::make()
                ->title('Export failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return null;
        }

        if ($result['total'] === 0) {
            Notification::make()
                ->title('Nothing to export')
                ->warning()
                ->send();

            return null;
        }

        $type = (string) $result['type'];
        $rows = $this->mapSearchRows($type, $result['rows']);
        $exportedCount = count($rows);
        $trueTotal = (int) $result['total'];
        $truncated = (bool) $result['truncated'];
        $filename = 'find-recall-'.$type.'-'.now()->format('Ymd-His').'.csv';

        if ($truncated) {
            Notification::make()
                ->title('Export limited to 1,000 rows')
                ->body('Exported '.$exportedCount.' of '.$trueTotal.' matches. Refine your search for a complete CSV, or use the track-and-trace export API for larger extracts.')
                ->warning()
                ->send();
        }

        return $this->streamFindRecallCsv($type, $rows, $exportedCount, $trueTotal, $truncated, $filename);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function streamFindRecallCsv(
        string $type,
        array $rows,
        int $exportedCount,
        int $trueTotal,
        bool $truncated,
        string $filename,
    ): StreamedResponse {
        return response()->streamDownload(function () use ($type, $rows, $exportedCount, $trueTotal, $truncated): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            $summary = 'Exported '.$exportedCount.' of '.$trueTotal.' matches';
            if ($truncated) {
                $summary .= ' (truncated — refine search for a complete export)';
            }
            fputcsv($out, ['# '.$summary]);

            if ($type === 'documents') {
                fputcsv($out, ['id', 'creation_date', 'asn_number', 'customer_po', 'status']);
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row['id'] ?? '',
                        $row['creation_date'] ?? '',
                        $row['asn_number'] ?? '',
                        $row['customer_po'] ?? '',
                        $row['status'] ?? '',
                    ]);
                }
            } else {
                fputcsv($out, ['id', 'gtin14', 'lot_number', 'serial_number', 'sscc18', 'epc_uri', 'document_id']);
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row['id'] ?? '',
                        $row['gtin14'] ?? '',
                        $row['lot_number'] ?? '',
                        $row['serial_number'] ?? '',
                        $row['sscc18'] ?? '',
                        $row['epc_uri'] ?? '',
                        $row['document_id'] ?? '',
                    ]);
                }
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Clear Find / Recall criteria and any inbound filters it applied.
     */
    private function resetFindRecallState(): void
    {
        $this->findRecallFormState = null;
        $this->selectedEpcIds = [];
        $this->schemaSearchPayload = null;
        $this->clearInboundFiltersAppliedByFindRecall();
    }

    /**
     * Remove only the table filters Find / Recall can set (leave other filters alone).
     */
    private function clearInboundFiltersAppliedByFindRecall(): void
    {
        $filters = is_array($this->tableFilters) ? $this->tableFilters : [];
        unset(
            $filters['asn_number'],
            $filters['lot_number'],
            $filters['gtin14'],
            $filters['ship_from_gln'],
            $filters['ship_to_gln'],
            $filters['sender_gln'],
            $filters['receiver_gln'],
            $filters['customer_po'],
            $filters['status'],
            $filters['direction'],
            $filters['trading_partner_id'],
            $filters['dscsa_affirm'],
            $filters['creation_date'],
            $filters['received_at'],
        );

        $this->tableFilters = $filters;

        try {
            $this->getTableFiltersForm()->fill($this->tableFilters);
        } catch (Throwable) {
            // Form may not be cached yet on first paint; tableFilters alone still drives query.
        }

        $this->resetPage();
    }

    /**
     * Apply Find / Recall document rules onto the inbound files table filters.
     *
     * @param  list<array{field?: string, operator?: string, value?: mixed}>  $rules
     */
    private function applyInboundListFiltersFromRules(array $rules): void
    {
        $this->clearInboundFiltersAppliedByFindRecall();
        $filters = is_array($this->tableFilters) ? $this->tableFilters : [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $field = (string) ($rule['field'] ?? '');
            $operator = (string) ($rule['operator'] ?? '');

            if ($field === 'doc.creation_date' || $field === 'doc.received_at') {
                if (! in_array($operator, ['eq', 'between'], true)) {
                    continue;
                }

                $filterKey = $field === 'doc.creation_date' ? 'creation_date' : 'received_at';
                $from = trim((string) ($rule['value'] ?? ''));
                $until = $operator === 'between'
                    ? trim((string) ($rule['value_to'] ?? ''))
                    : $from;

                if ($from === '' && $until === '') {
                    continue;
                }

                $filters[$filterKey] = array_filter([
                    'from' => $from !== '' ? $from : null,
                    'until' => $until !== '' ? $until : null,
                ], static fn ($v): bool => $v !== null);

                continue;
            }

            if ($operator === 'is_true' || $operator === 'is_false') {
                if ($field === 'doc.dscsa_affirm') {
                    $filters['dscsa_affirm'] = ['value' => $operator === 'is_true' ? true : false];
                }

                continue;
            }

            if ($operator !== 'eq') {
                continue;
            }

            $value = trim((string) ($rule['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $filterKey = match ($field) {
                'doc.asn_or_po', 'doc.asn_number' => 'asn_number',
                'doc.customer_po' => 'customer_po',
                'ilmd.lot_number' => 'lot_number',
                'epc.gtin14' => 'gtin14',
                'doc.ship_from_gln' => 'ship_from_gln',
                'doc.ship_to_gln' => 'ship_to_gln',
                'doc.sender_gln' => 'sender_gln',
                'doc.receiver_gln' => 'receiver_gln',
                'doc.status' => 'status',
                'doc.direction' => 'direction',
                'doc.trading_partner_id' => 'trading_partner_id',
                default => null,
            };

            if ($filterKey === null) {
                continue;
            }

            $filters[$filterKey] = ['value' => $filterKey === 'trading_partner_id' ? (int) $value : $value];
        }

        $this->tableFilters = $filters;

        try {
            $this->getTableFiltersForm()->fill($this->tableFilters);
        } catch (Throwable) {
            // Form may not be cached yet on first paint; tableFilters alone still drives query.
        }

        $this->resetPage();
    }

    /**
     * @param  list<array{field?: string, operator?: string, value?: mixed}>  $rules
     */
    private function noMatchesBodyForRules(array $rules): string
    {
        $default = 'No matches found. Try adjusting GTIN, lot, ASN, or PO.';

        if (count($rules) !== 1) {
            return $default;
        }

        $rule = $rules[0];
        $field = (string) ($rule['field'] ?? '');
        $operator = (string) ($rule['operator'] ?? '');
        $value = trim((string) ($rule['value'] ?? ''));

        if ($value === '' || $operator !== 'eq') {
            return $default;
        }

        $sibling = match ($field) {
            'doc.asn_number' => ['column' => 'customer_po', 'label' => 'Customer PO', 'preset' => 'By ASN or PO'],
            'doc.customer_po' => ['column' => 'asn_number', 'label' => 'ASN', 'preset' => 'By ASN or PO'],
            default => null,
        };

        if ($sibling === null || ! Schema::hasColumn('epcis_documents', $sibling['column'])) {
            return $default;
        }

        $exists = EpcisDocument::query()
            ->where($sibling['column'], $value)
            ->exists();

        if (! $exists) {
            return $default;
        }

        return "No matches on this field. \"{$value}\" exists as {$sibling['label']} — try the {$sibling['preset']} preset.";
    }

    /**
     * @param  Collection<int, Epc|EpcisDocument>  $rows
     * @return list<array<string, mixed>>
     */
    private function mapSearchRows(string $type, Collection $rows): array
    {
        if ($type === 'documents') {
            return $rows->map(function (EpcisDocument $doc): array {
                return [
                    'id' => (int) $doc->getKey(),
                    'creation_date' => $doc->creation_date?->toDateTimeString(),
                    'asn_number' => $doc->asn_number,
                    'customer_po' => $doc->customer_po,
                    'status' => $doc->status,
                    'view_url' => EpcisDocumentResource::getUrl('view', ['record' => $doc], panel: 'app'),
                ];
            })->all();
        }

        /** @var Collection<int, Epc> $epcRows */
        $epcRows = $rows;
        $epcIds = $epcRows->map(fn (Epc $epc): int => (int) $epc->getKey())->all();
        $documentByEpc = $this->batchDocumentIdsForEpcs($epcIds);
        $this->shipToSiteByEpcId = $this->batchShipToSiteIdsForEpcs($documentByEpc);

        return $epcRows->map(function (Epc $epc) use ($documentByEpc): array {
            $documentId = $documentByEpc[(int) $epc->getKey()] ?? null;

            return [
                'id' => (int) $epc->getKey(),
                'epc_uri' => $epc->epc_uri,
                'gtin14' => $epc->gtin14,
                'sscc18' => $epc->sscc18,
                'serial_number' => $epc->serial_number,
                'lot_number' => $epc->ilmd?->lot_number,
                'document_id' => $documentId,
                'view_url' => $documentId !== null
                    ? EpcisDocumentResource::getUrl('view', ['record' => $documentId], panel: 'app')
                    : null,
            ];
        })->all();
    }

    /**
     * @param  list<int>  $epcIds
     * @return array<int, int> epc_id => document_id
     */
    private function batchDocumentIdsForEpcs(array $epcIds): array
    {
        if ($epcIds === [] || ! Schema::hasTable('document_epcs')) {
            return [];
        }

        $query = DB::table('document_epcs')
            ->whereIn('document_epcs.epc_id', $epcIds);

        $this->scopeActiveDocumentEpcLinks($query);
        $this->orderLatestDocumentForEpcLink($query);

        $links = $query->get(['document_epcs.epc_id', 'document_epcs.document_id']);

        $documentByEpc = [];
        foreach ($links as $link) {
            $epcId = (int) $link->epc_id;
            if (! isset($documentByEpc[$epcId])) {
                $documentByEpc[$epcId] = (int) $link->document_id;
            }
        }

        return $documentByEpc;
    }

    /**
     * @param  array<int, int>  $documentByEpc  epc_id => document_id
     * @return array<int, int|null> epc_id => ship_to_site_id
     */
    private function batchShipToSiteIdsForEpcs(array $documentByEpc): array
    {
        if ($documentByEpc === []) {
            return [];
        }

        $documentIds = array_values(array_unique(array_map(intval(...), array_values($documentByEpc))));

        $shipToByDocument = EpcisDocument::query()
            ->whereIn('id', $documentIds)
            ->pluck('ship_to_site_id', 'id');

        $shipToByEpc = [];
        foreach ($documentByEpc as $epcId => $documentId) {
            $siteId = $shipToByDocument[$documentId] ?? null;
            $shipToByEpc[(int) $epcId] = $siteId !== null ? (int) $siteId : null;
        }

        return $shipToByEpc;
    }

    private function scopeActiveDocumentEpcLinks(Builder $query): void
    {
        $query->join('epcis_documents', 'epcis_documents.id', '=', 'document_epcs.document_id');

        if (Schema::hasColumn('epcis_documents', 'ingest_generation')
            && Schema::hasColumn('document_epcs', 'ingest_generation')) {
            $query->whereColumn(
                'document_epcs.ingest_generation',
                'epcis_documents.ingest_generation',
            );
        }
    }

    private function orderLatestDocumentForEpcLink(Builder $query): void
    {
        $query->orderByDesc('epcis_documents.processed_at')
            ->orderByDesc('epcis_documents.received_at')
            ->orderByDesc('epcis_documents.id');
    }
}
