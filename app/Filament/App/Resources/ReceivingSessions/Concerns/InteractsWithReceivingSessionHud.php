<?php

namespace App\Filament\App\Resources\ReceivingSessions\Concerns;

use App\Actions\Receiving\AttachReceivingSessionInvoice;
use App\Actions\Receiving\CancelReceivingSession;
use App\Actions\Receiving\CloseOpenToteReceiving;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\ConfirmRemainingExpectedReceivingLines;
use App\Actions\Receiving\CopyConfirmedReceivingScansToSession;
use App\Actions\Receiving\DeleteReceivingSession;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\PropagateScanFirstConfirmsToAsnSession;
use App\Actions\Receiving\ResetReceivingSessionScans;
use App\Actions\Receiving\SeedOnDocumentConfirmedEpcsOntoAsnSession;
use App\Actions\Receiving\UnpackReceivingHierarchy;
use App\Actions\Vrs\QueueProductVerificationFromReceive;
use App\Enums\ReceivingSessionKind;
use App\Filament\App\Pages\ReceivingIssues;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Resources\ReceivingSessions\RelationManagers\ScanLinesRelationManager;
use App\Filament\Support\Floor\UnsubmittedSessionDeleteAction;
use App\Filament\Support\RegulatoryCompliance;
use App\Jobs\GenerateReceivingLpnLabelJob;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\LabelPrinter;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Support\Fda\ScheduledProductPresence;
use App\Support\Fda\ScheduledSessionChip;
use App\Support\Gs1\ElementString;
use App\Support\Receiving\ReceiveLayout;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\Receiving\ReceivingScanLevel;
use App\Support\Receiving\ReceivingSessionStatus;
use App\Support\Receiving\ResolveReceiveScanContext;
use App\Support\Receiving\ResolveReceivingSite;
use App\Support\TenantFeatures;
use App\Support\TenantSsccSettings;
use App\Support\Tracing\AssetTrackingUrl;
use App\Support\Tracing\EpcContextLinks;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use App\Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

trait InteractsWithReceivingSessionHud
{
    public ?string $scan = '';

    /** @var list<string> */
    public array $stagedScans = [];

    #[Locked]
    public bool $confirmStagedInFlight = false;

    private const MAX_STAGED_SCANS = 50;

    public bool $autoConfirmChildren = false;

    /** Pharmacy: opt-in unpack when completing (ASN auto-complete or scan-first). Default sealed. */
    public bool $unpackOnComplete = false;

    public ?string $lastScanMessage = null;

    /** @var 'ok'|'warn'|'error'|null */
    public ?string $lastScanTone = null;

    public ?string $lastScanDetail = null;

    public ?string $lastScanHref = null;

    public ?int $lastScanEpcId = null;

    /** @var list<array{key: string, label: string, url: ?string, opens: bool}> */
    public array $lastScanContextLinks = [];

    public bool $highlightUnexpected = false;

    public ?bool $chipHasTi = null;

    public ?int $chipMatchedAsnDocumentId = null;

    public ?string $chipMatchedAsnLabel = null;

    public ?int $chipTransferSessionId = null;

    public ?string $chipDeaSchedule = null;

    public ?bool $chipDeaMissingParty = null;

    public ?string $chipDeaLabel = null;

    public ?string $chipDeaColor = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->loadMissing(['document', 'tradingPartner', 'site', 'matchedDocument', 'transferringSession', 'activeParentEpc']);

        if ($this->getRecord()->status !== 'completed') {
            $this->autoConfirmChildren = $this->receivingPolicy()->defaultAutoConfirmChildren();
        }

        if ($this->canShowUnpackOnComplete()) {
            $this->unpackOnComplete = true;
        }

        $this->hydrateChipsFromSession();
        $this->backfillAsnSiteAndPropagateScanFirstConfirms();

        if ($scan = request()->query('scan')) {
            $this->scan = (string) $scan;
        }
    }

    private function backfillAsnSiteAndPropagateScanFirstConfirms(): void
    {
        /** @var ReceivingSession $record */
        $record = $this->getRecord();

        if (! $record->isInboundAsn() || ! in_array($record->status, ['open', 'in_progress'], true)) {
            return;
        }

        try {
            $propagate = app(PropagateScanFirstConfirmsToAsnSession::class)->handle($record->fresh(), auth()->id());
            $this->notifyScanFirstCopyIssues($propagate);
        } catch (DomainException) {
            // Best-effort reconcile on view; do not block the page.
        }

        try {
            if ($record->site_id === null && $record->document !== null) {
                $resolvedSiteId = app(ResolveReceivingSite::class)->handle($record->document);
                $record->forceFill(['site_id' => $resolvedSiteId])->save();
                $record->refresh()->loadMissing(['document', 'tradingPartner', 'site', 'matchedDocument', 'transferringSession', 'activeParentEpc']);
            }
        } catch (DomainException) {
            // Best-effort site backfill on view.
        }
    }

    #[On('receiving-session-hud-refresh')]
    public function refreshReceivingHud(): void
    {
        $this->getRecord()->refresh()->loadMissing(['document', 'tradingPartner', 'site', 'matchedDocument', 'transferringSession', 'activeParentEpc']);
        $this->highlightUnexpected = false;
        $this->hydrateChipsFromSession();
    }

    public function receivingPolicy(): ReceivingPolicy
    {
        return ReceivingPolicy::forTenant(tenant());
    }

    public function sessionKind(): ReceivingSessionKind
    {
        return $this->receivingPolicy()->resolveKind($this->getRecord());
    }

    /**
     * @return array{scanHelper: string, sealedPalletLabel: string, sealedPalletHelper: string, confirmLabelSealed: string, confirmLabel: string, kindHelper: string, confirmButton: string, unexpectedTitle: string, unexpectedBody: string, completeTitle: string, completeBody: string}
     */
    public function promptCopy(): array
    {
        $copy = $this->receivingPolicy()->promptCopy($this->getRecord());

        if ($this->hasOpenToteLock()) {
            $copy['scanHelper'] = 'Scan unit in open tote';
        }

        return $copy;
    }

    public function isOpenToteMode(): bool
    {
        return $this->receivingPolicy()->edgeMode() === ReceivingEdgeMode::OpenTote;
    }

    public function hasOpenToteLock(): bool
    {
        return $this->isOpenToteMode() && $this->getRecord()->active_parent_epc_id !== null;
    }

    public function openToteLockedParentLabel(): ?string
    {
        if (! $this->hasOpenToteLock()) {
            return null;
        }

        $label = $this->getRecord()->openToteLabel();

        return filled($label) ? $label : null;
    }

    public function openToteLockedChildProgress(): ?string
    {
        if (! $this->hasOpenToteLock()) {
            return null;
        }

        /** @var ReceivingSession $record */
        $record = $this->getRecord();
        $parentId = (int) $record->active_parent_epc_id;

        $counts = ReceivingScanLine::query()
            ->where('receiving_session_id', $record->getKey())
            ->where('line_role', 'child')
            ->where('parent_epc_id', $parentId)
            ->selectRaw("sum(status = 'confirmed') as confirmed_count, count(*) as expected_count")
            ->first();

        return ((int) ($counts?->confirmed_count ?? 0)).'/'.((int) ($counts?->expected_count ?? 0));
    }

    public function canCloseOpenTote(): bool
    {
        if ($this->isCompleted() || ! $this->hasOpenToteLock()) {
            return false;
        }

        return $this->getRecord()->isInboundAsn();
    }

    public function canAcceptRemaining(): bool
    {
        if ($this->isCompleted() || ! $this->getRecord()->isInboundAsn()) {
            return false;
        }

        return in_array($this->getRecord()->status, ['open', 'in_progress'], true);
    }

    public function acceptRemainingEnabled(): bool
    {
        if (! $this->canAcceptRemaining()) {
            return false;
        }

        if ($this->isOpenToteMode()) {
            return $this->hasOpenToteLock();
        }

        $sessionId = $this->getRecord()->getKey();

        return ReceivingScanLine::query()
            ->where('receiving_session_id', $sessionId)
            ->whereIn('line_role', ['parent', 'child'])
            ->where('status', 'expected')
            ->exists();
    }

    public function getHeading(): string|Htmlable|null
    {
        /** @var ReceivingSession $record */
        $record = $this->getRecord();
        $kind = $this->sessionKind();

        if ($kind === ReceivingSessionKind::TransferReceive) {
            $transferId = $record->transferring_session_id;

            return $transferId !== null
                ? 'Receive transfer #'.$transferId
                : 'Transfer receive #'.$record->getKey();
        }

        if ($kind === ReceivingSessionKind::ScanFirst) {
            $siteName = $record->site?->name;

            return filled($siteName)
                ? 'Scan-first receive · '.$siteName
                : 'Scan-first receive #'.$record->getKey();
        }

        $partnerName = $record->tradingPartner?->name;
        if (filled($partnerName)) {
            return 'Receive · '.$partnerName;
        }

        $filename = $record->document?->original_filename;
        if (filled($filename)) {
            return 'Receive '.$filename;
        }

        return 'Receiving session #'.$record->getKey();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    public function statusLabel(): string
    {
        return ReceivingSessionStatus::label($this->getRecord()->status);
    }

    public function kindBadgeLabel(): string
    {
        return $this->sessionKind()->badgeLabel();
    }

    public function edgeModeChipLabel(): string
    {
        return $this->receivingPolicy()->edgeMode()->chipLabel();
    }

    public function attachedInvoiceFilename(): ?string
    {
        $filename = $this->getRecord()->invoice_original_filename;

        return filled($filename) ? (string) $filename : null;
    }

    public function canAttachInvoice(): bool
    {
        return $this->isScanFirst() && ! $this->isCompleted();
    }

    public function isCompleted(): bool
    {
        return $this->getRecord()->status === 'completed';
    }

    public function isScanFirst(): bool
    {
        return $this->getRecord()->isScanFirst();
    }

    public function isInboundAsn(): bool
    {
        return $this->getRecord()->isInboundAsn();
    }

    public function isTransferReceive(): bool
    {
        return $this->getRecord()->isTransferReceive();
    }

    public function statusBadgeColor(): string
    {
        return match ($this->getRecord()->status) {
            'completed' => 'success',
            'in_progress' => 'warning',
            default => 'outline',
        };
    }

    public function confirmedCount(): int
    {
        /** @var ReceivingSession $record */
        $record = $this->getRecord();

        return (int) $record->confirmed_parent_count + (int) $record->confirmed_child_count;
    }

    /**
     * Whether child-level progress is meaningful for this session.
     */
    public function showUnitsProgress(): bool
    {
        return ! ($this->isTransferReceive() && (int) $this->getRecord()->expected_child_count === 0);
    }

    /**
     * Parent hierarchy type from preferred scan level (transfer → Lines).
     */
    public function parentTypeLabel(): string
    {
        if ($this->isTransferReceive()) {
            return 'Lines';
        }

        return match ($this->receivingPolicy()->preferredScanLevel()) {
            ReceivingScanLevel::Pallet => 'Pallets',
            ReceivingScanLevel::Case, ReceivingScanLevel::ToteOrCase => 'Cases',
        };
    }

    /**
     * Child hierarchy type under the preferred scan level.
     */
    public function childTypeLabel(): string
    {
        if ($this->isTransferReceive()) {
            return 'Units';
        }

        return match ($this->receivingPolicy()->preferredScanLevel()) {
            ReceivingScanLevel::Pallet => 'Cases',
            ReceivingScanLevel::Case, ReceivingScanLevel::ToteOrCase => 'Units',
        };
    }

    /** @deprecated Use parentTypeLabel() */
    public function parentMetricLabel(): string
    {
        return $this->parentTypeLabel();
    }

    /**
     * Quantity fragment: scan-first "4"; ASN/file "2/7".
     */
    public function parentProgressQuantity(): string
    {
        $record = $this->getRecord();
        $confirmed = (int) $record->confirmed_parent_count;

        if ($this->isScanFirst()) {
            return (string) $confirmed;
        }

        return $confirmed.'/'.(int) $record->expected_parent_count;
    }

    /**
     * Quantity fragment for children: scan-first "256"; ASN/file "512/354".
     */
    public function childProgressQuantity(): string
    {
        $record = $this->getRecord();
        $confirmed = (int) $record->confirmed_child_count;

        if ($this->isScanFirst()) {
            return (string) $confirmed;
        }

        return $confirmed.'/'.(int) $record->expected_child_count;
    }

    /**
     * Parent chip, e.g. "4 Pallets" or "2/7 Pallets".
     */
    public function parentProgressChipLabel(): string
    {
        return $this->parentProgressQuantity().' '.$this->parentTypeLabel();
    }

    /**
     * Child chip, e.g. "256 Cases" or "1002/5844 Units".
     */
    public function childProgressChipLabel(): string
    {
        return $this->childProgressQuantity().' '.$this->childTypeLabel();
    }

    /** @deprecated Use childProgressChipLabel() */
    public function unitsProgressChipLabel(): string
    {
        return $this->childProgressChipLabel();
    }

    /**
     * Combined label for aria / tests.
     */
    public function progressChipLabel(): string
    {
        if (! $this->showUnitsProgress()) {
            return $this->parentProgressChipLabel();
        }

        return $this->parentProgressChipLabel().' · '.$this->childProgressChipLabel();
    }

    public function progressChipAriaLabel(): string
    {
        return $this->progressChipLabel();
    }

    public function canCompleteManually(): bool
    {
        if ($this->isCompleted()) {
            return false;
        }

        if ($this->isScanFirst()) {
            return $this->confirmedCount() > 0;
        }

        if ($this->isInboundAsn()) {
            /** @var ReceivingSession $record */
            $record = $this->getRecord();

            if (! in_array($record->status, ['open', 'in_progress'], true)) {
                return false;
            }

            return $record->isReadyToCompleteInboundAsn();
        }

        if ($this->isTransferReceive()) {
            /** @var ReceivingSession $record */
            $record = $this->getRecord();

            if (! in_array($record->status, ['open', 'in_progress'], true)) {
                return false;
            }

            if ($this->confirmedCount() < 1) {
                return false;
            }

            // Recovery after last-scan EPCIS failure: all lines confirmed, session reverted.
            return ! $record->scanLines()
                ->where('status', 'expected')
                ->exists();
        }

        return false;
    }

    public function canCloseTransferWithShortage(): bool
    {
        if (! $this->isTransferReceive() || $this->isCompleted()) {
            return false;
        }

        /** @var ReceivingSession $record */
        $record = $this->getRecord();

        if (! in_array($record->status, ['open', 'in_progress'], true)) {
            return false;
        }

        if ($record->receiving_events_generated_at !== null) {
            return false;
        }

        $transfer = $record->transferringSession;
        if ($transfer !== null && $transfer->receive_events_generated_at !== null) {
            return false;
        }

        return ReceivingScanLine::query()
            ->where('receiving_session_id', $record->getKey())
            ->where('status', 'expected')
            ->exists();
    }

    public function transferReceiveEpcisPending(): bool
    {
        if (! $this->isTransferReceive() || ! $this->isCompleted()) {
            return false;
        }

        /** @var ReceivingSession $record */
        $record = $this->getRecord();

        if ($record->receiving_events_generated_at !== null) {
            return false;
        }

        $transfer = $record->transferringSession;

        return $transfer !== null && $transfer->receive_events_generated_at === null;
    }

    public function canRetryReceiveEpcis(): bool
    {
        if (! $this->transferReceiveEpcisPending()) {
            return false;
        }

        $transfer = $this->getRecord()->transferringSession;

        return $transfer !== null && (int) $transfer->received_count > 0;
    }

    public function canShowUnpackOnComplete(): bool
    {
        return ! $this->isCompleted()
            && $this->receivingPolicy()->canUnpackAtReceive();
    }

    public function canUnpackHierarchy(): bool
    {
        if (! $this->isCompleted() || ! $this->receivingPolicy()->canUnpackAfterReceive()) {
            return false;
        }

        /** @var ReceivingSession $record */
        $record = $this->getRecord();

        if ($record->receiving_events_generated_at === null) {
            return false;
        }

        return app(UnpackReceivingHierarchy::class)
            ->confirmedParentEpcIdsWithOpenLinks($record) !== [];
    }

    public function canMatchAsn(): bool
    {
        if ($this->isCompleted() || ! $this->isScanFirst()) {
            return false;
        }

        return $this->chipMatchedAsnDocumentId !== null || $this->getRecord()->matched_epcis_document_id !== null;
    }

    protected function usesStagedScans(): bool
    {
        return false;
    }

    public function stageScan(?string $raw = null): void
    {
        /** @var ReceivingSession $session */
        $session = $this->getRecord();

        if ($session->status === 'completed') {
            $this->setLastScan('error', 'Receiving is already complete for this session.');

            Notification::make()
                ->title('Already complete')
                ->danger()
                ->ephemeral()->send();

            $this->dispatch('scan-result', tone: 'error');

            return;
        }

        $scan = ElementString::normalize(trim($raw ?? (string) $this->scan));

        if ($scan === '') {
            $this->setLastScan(
                'error',
                $this->isTransferReceive()
                    ? 'Scan an SSCC or SGTIN to receive at destination.'
                    : 'Scan an SSCC or SGTIN to confirm.',
            );

            Notification::make()
                ->title('Scan required')
                ->danger()
                ->ephemeral()->send();

            $this->dispatch('focus-scan');
            $this->dispatch('scan-result', tone: 'error');

            return;
        }

        if (in_array($scan, $this->stagedScans, true)) {
            $this->scan = '';
            $this->setLastScan('warn', 'Scan already staged.');

            $this->dispatch('focus-scan');
            $this->dispatch('scan-result', tone: 'warn');

            return;
        }

        if (count($this->stagedScans) >= self::MAX_STAGED_SCANS) {
            $this->setLastScan('error', sprintf('Staged scan limit is %d.', self::MAX_STAGED_SCANS));

            Notification::make()
                ->title('Staged scan limit reached')
                ->body(sprintf('Remove a staged scan or confirm before adding more (max %d).', self::MAX_STAGED_SCANS))
                ->danger()
                ->ephemeral()->send();

            $this->dispatch('focus-scan');
            $this->dispatch('scan-result', tone: 'error');

            return;
        }

        $this->stagedScans[] = $scan;
        $this->scan = '';

        $count = count($this->stagedScans);
        $this->setLastScan('ok', sprintf('Staged (%d). Confirm when ready.', $count));

        $this->dispatch('focus-scan');
        $this->dispatch('scan-result', tone: 'ok');
    }

    public function removeStagedScan(int $index): void
    {
        if (! array_key_exists($index, $this->stagedScans)) {
            return;
        }

        unset($this->stagedScans[$index]);
        $this->stagedScans = array_values($this->stagedScans);
    }

    public function clearStagedScans(): void
    {
        $this->stagedScans = [];
    }

    public function confirmStagedScans(): void
    {
        if ($this->confirmStagedInFlight) {
            return;
        }

        if ($this->stagedScans === []) {
            Notification::make()
                ->title('No staged scans')
                ->warning()
                ->ephemeral()->send();

            return;
        }

        /** @var ReceivingSession $session */
        $session = $this->getRecord();

        if ($session->status === 'completed') {
            $this->setLastScan('error', 'Receiving is already complete for this session.');

            Notification::make()
                ->title('Already complete')
                ->danger()
                ->ephemeral()->send();

            $this->dispatch('scan-result', tone: 'error');

            return;
        }

        $this->confirmStagedInFlight = true;
        $this->highlightUnexpected = false;

        try {
            $this->autoConfirmChildren = $this->receivingPolicy()->defaultAutoConfirmChildren();

            /** @var list<array{scan: string, message: string}> $failures */
            $failures = [];
            $okCount = 0;
            /** @var array<string, mixed>|null $lastResult */
            $lastResult = null;
            $lastScan = null;

            foreach ($this->stagedScans as $scan) {
                try {
                    $result = app(ConfirmReceivingScan::class)->handle(
                        $session,
                        $scan,
                        auth()->id(),
                        $this->autoConfirmChildren,
                        unpack: $this->unpackOnComplete && $this->receivingPolicy()->canUnpackAtReceive(),
                    );
                } catch (InvalidArgumentException|DomainException $e) {
                    $failures[] = ['scan' => $scan, 'message' => $e->getMessage()];
                    $lastResult = [
                        'ok' => false,
                        'effect' => 'not_in_session',
                        'message' => $e->getMessage(),
                    ];
                    $lastScan = $scan;

                    continue;
                }

                $lastResult = $result;
                $lastScan = $scan;

                if (($result['ok'] ?? false) !== true) {
                    $failures[] = [
                        'scan' => $scan,
                        'message' => (string) ($result['message'] ?? 'Confirm failed.'),
                    ];

                    continue;
                }

                $okCount++;
                app(QueueProductVerificationFromReceive::class)->handle($result, $scan, auth()->id());
                $session = $session->fresh() ?? $session;
            }

            $this->stagedScans = array_column($failures, 'scan');
            $this->getRecord()->refresh()->loadMissing(['document', 'tradingPartner', 'site', 'matchedDocument', 'transferringSession', 'activeParentEpc']);

            if ($lastResult !== null && $lastScan !== null) {
                $this->applyConfirmContext($lastResult, $lastScan);

                $effect = (string) ($lastResult['effect'] ?? '');
                $this->highlightUnexpected = $effect === 'unexpected'
                    && $this->sessionKind() !== ReceivingSessionKind::ScanFirst;
            }

            if ($failures !== []) {
                $message = $okCount > 0
                    ? sprintf('Confirmed %d; %d failed.', $okCount, count($failures))
                    : (string) ($lastResult['message'] ?? sprintf('%d scan(s) failed.', count($failures)));
                $tone = 'error';
            } else {
                $message = sprintf('Confirmed %d scan(s).', $okCount);
                $tone = 'ok';
            }

            $this->setLastScan($tone, $message);

            $notification = Notification::make()->title($message);

            match ($tone) {
                'ok' => $notification->success(),
                'warn' => $notification->warning(),
                default => $notification->danger(),
            };

            if ($failures !== []) {
                $failureLines = array_map(
                    fn (array $failure): string => sprintf(
                        '%s — %s',
                        $failure['scan'],
                        $failure['message'],
                    ),
                    $failures,
                );
                $notification->body(implode("\n", $failureLines));
            }

            $notification->ephemeral()->send();

            $this->scan = '';
            $this->dispatch('focus-scan');
            $this->dispatch('scan-result', tone: $tone);
            $this->dispatch('receiving-scan-lines-updated')
                ->to(ScanLinesRelationManager::class);
        } finally {
            $this->confirmStagedInFlight = false;
        }
    }

    public function confirmScanAction(): Action
    {
        // Floor scan is audited via receiving_scan_lines (confirmed_by / confirmed_at /
        // scan_raw). Do not password-gate high-frequency SSCC/SGTIN confirms.
        return Action::make('confirmScan')
            ->label('Confirm')
            ->action(function (): void {
                /** @var ReceivingSession $session */
                $session = $this->getRecord();

                if ($session->status === 'completed') {
                    $this->setLastScan('error', 'Receiving is already complete for this session.');

                    Notification::make()
                        ->title('Already complete')
                        ->danger()
                        ->ephemeral()->send();

                    $this->dispatch('scan-result', tone: 'error');

                    return;
                }

                $scan = ElementString::normalize(trim((string) $this->scan));
                $this->scan = $scan;

                if ($scan === '') {
                    $this->setLastScan(
                        'error',
                        $this->isTransferReceive()
                            ? 'Scan an SSCC or SGTIN to receive at destination.'
                            : 'Scan an SSCC or SGTIN to confirm.',
                    );

                    Notification::make()
                        ->title('Scan required')
                        ->danger()
                        ->ephemeral()->send();

                    $this->dispatch('focus-scan');
                    $this->dispatch('scan-result', tone: 'error');

                    return;
                }

                // Resolve at confirm time — do not rely on dehydrated mount default (false).
                $this->autoConfirmChildren = $this->receivingPolicy()->defaultAutoConfirmChildren();

                try {
                    $result = app(ConfirmReceivingScan::class)->handle(
                        $session,
                        $scan,
                        auth()->id(),
                        $this->autoConfirmChildren,
                        unpack: $this->unpackOnComplete && $this->receivingPolicy()->canUnpackAtReceive(),
                    );
                } catch (InvalidArgumentException|DomainException $e) {
                    // The scan itself is only ever committed inside ConfirmReceivingScan's
                    // own transaction(s); an exception here means it never landed, so
                    // there is nothing to reconcile beyond telling the operator why.
                    $this->setLastScan('error', $e->getMessage());

                    Notification::make()
                        ->title('Scan not confirmed')
                        ->body($e->getMessage())
                        ->danger()
                        ->ephemeral()->send();

                    $this->getRecord()->refresh()->loadMissing(['document', 'tradingPartner', 'site', 'matchedDocument', 'transferringSession', 'activeParentEpc']);
                    $this->dispatch('scan-result', tone: 'error');

                    return;
                }

                $this->scan = '';
                $this->getRecord()->refresh()->loadMissing(['document', 'tradingPartner', 'site', 'matchedDocument', 'transferringSession', 'activeParentEpc']);

                $this->applyConfirmContext($result, $scan);

                if (($result['ok'] ?? false) === true) {
                    app(QueueProductVerificationFromReceive::class)->handle($result, $scan, auth()->id());
                }

                $tone = match ($result['effect'] ?? null) {
                    'already_confirmed', 'already_received' => 'warn',
                    'parent_confirmed', 'child_confirmed', 'confirmed', 'received', 'completed' => 'ok',
                    'double_receive' => 'error',
                    default => ($result['ok'] ?? false) ? 'ok' : 'error',
                };

                $tiWarning = filled($result['ti_warning'] ?? null)
                    ? (string) $result['ti_warning']
                    : null;

                if ($tiWarning !== null) {
                    $tone = $tone === 'ok' ? 'warn' : $tone;
                }

                // Scan committed, but completion/EPCIS authoring failed afterward —
                // still a warning, not the green "confirmed" tone.
                if (filled($result['completion_error'] ?? null)) {
                    $tone = $tone === 'ok' ? 'warn' : $tone;
                }

                $effect = (string) ($result['effect'] ?? '');
                $this->highlightUnexpected = $effect === 'unexpected'
                    && $this->sessionKind() !== ReceivingSessionKind::ScanFirst;

                $message = (string) ($result['message'] ?? 'Scan processed.');
                if ($this->isScanFirst() && str_contains($message, 'Not on this ASN')) {
                    $message = $this->promptCopy()['unexpectedTitle'];
                    $this->highlightUnexpected = true;
                }

                if ($tiWarning !== null && ! str_contains($message, $tiWarning)) {
                    $message = trim($message.' '.$tiWarning);
                }

                $reconciledAsnSessionId = $result['reconciled_asn_session_id'] ?? null;
                if ($reconciledAsnSessionId !== null) {
                    $message = trim($message.sprintf(' Also confirmed on ASN receiving #%d.', $reconciledAsnSessionId));
                }

                $this->setLastScan(
                    $tone,
                    $message,
                    $this->identifierFor($result['epc'] ?? null),
                    AssetTrackingUrl::forEpc($result['epc'] ?? null),
                    $result['epc'] ?? null,
                );

                $notification = Notification::make()->title($message);

                if ($tiWarning !== null) {
                    $notification->body($tiWarning);
                }

                match ($tone) {
                    'ok' => $notification->success(),
                    'warn' => $notification->warning(),
                    default => $notification->danger(),
                };

                $notification->ephemeral()->send();

                $this->dispatch('focus-scan');
                $this->dispatch('scan-result', tone: $tone);
                $this->dispatch('receiving-scan-lines-updated')
                    ->to(ScanLinesRelationManager::class);
            });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function applyConfirmContext(array $result, string $scan): void
    {
        if (array_key_exists('has_ti', $result)) {
            $this->chipHasTi = (bool) $result['has_ti'];
        }

        $matchedAsnId = $result['matched_asn_document_id']
            ?? $result['matched_inbound_document_id']
            ?? null;

        if ($matchedAsnId !== null) {
            $this->chipMatchedAsnDocumentId = (int) $matchedAsnId;
            $doc = EpcisDocument::query()->find($this->chipMatchedAsnDocumentId);
            $this->chipMatchedAsnLabel = $this->matchedAsnChipLabel($doc, $this->chipMatchedAsnDocumentId);
        }

        $transferId = $result['matched_transfer_session_id']
            ?? $result['in_transit_transferring_session_id']
            ?? $this->getRecord()->transferring_session_id;

        if ($transferId !== null) {
            $this->chipTransferSessionId = (int) $transferId;
        }

        if (! array_key_exists('has_ti', $result) || $matchedAsnId === null) {
            try {
                $context = app(ResolveReceiveScanContext::class)->handle($scan, $this->getRecord());
                $this->applyResolveContext($context);
            } catch (Throwable) {
                // Context chips are best-effort; confirm already succeeded/failed above.
            }
        }

        if ($this->getRecord()->matched_epcis_document_id !== null && $this->chipMatchedAsnDocumentId === null) {
            $this->hydrateChipsFromSession();
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function applyResolveContext(array $context): void
    {
        if (array_key_exists('has_ti', $context)) {
            $this->chipHasTi = (bool) $context['has_ti'];
        }

        if (! empty($context['matched_inbound_document_id'])) {
            $this->chipMatchedAsnDocumentId = (int) $context['matched_inbound_document_id'];
            $doc = $context['matched_inbound_document'] ?? null;
            $this->chipMatchedAsnLabel = $this->matchedAsnChipLabel(
                $doc instanceof EpcisDocument ? $doc : null,
                $this->chipMatchedAsnDocumentId,
            );
        }

        if (! empty($context['in_transit_transferring_session_id'])) {
            $this->chipTransferSessionId = (int) $context['in_transit_transferring_session_id'];
        }
    }

    private function hydrateChipsFromSession(): void
    {
        /** @var ReceivingSession $record */
        $record = $this->getRecord();

        if ($record->matched_epcis_document_id !== null) {
            $this->chipMatchedAsnDocumentId = (int) $record->matched_epcis_document_id;
            $doc = $record->matchedDocument;
            $this->chipMatchedAsnLabel = $this->matchedAsnChipLabel($doc, $this->chipMatchedAsnDocumentId);
        }

        if ($record->transferring_session_id !== null) {
            $this->chipTransferSessionId = (int) $record->transferring_session_id;
        }

        $this->hydrateDeaScheduleChip($record);
    }

    private function hydrateDeaScheduleChip(ReceivingSession $record): void
    {
        $gtins = $this->sessionGtin14s($record);
        $presence = ScheduledProductPresence::forGtins($gtins);
        $highest = $presence['highest'];

        $missing = false;
        if ($presence['has_scheduled']) {
            $partnerId = $record->trading_partner_id !== null
                ? (int) $record->trading_partner_id
                : ($record->document?->trading_partner_id !== null
                    ? (int) $record->document->trading_partner_id
                    : null);
            $missing = ! ScheduledSessionChip::partyHasDea($partnerId);
        }

        $this->chipDeaSchedule = $highest;
        $this->chipDeaMissingParty = $presence['has_scheduled'] ? $missing : null;
        $this->chipDeaLabel = ScheduledSessionChip::label($highest, $missing, 'No DEA on seller');
        $this->chipDeaColor = ScheduledSessionChip::badgeColor($highest);
    }

    /**
     * @return list<string>
     */
    private function sessionGtin14s(ReceivingSession $record): array
    {
        $gtins = [];

        if ($record->epcis_document_id !== null) {
            $document = $record->document ?? EpcisDocument::query()->find($record->epcis_document_id);
            if ($document !== null) {
                $gtins = array_merge($gtins, $document->epcsQuery()
                    ->whereNotNull('gtin14')
                    ->where('gtin14', '!=', '')
                    ->distinct()
                    ->pluck('gtin14')
                    ->map(fn ($gtin): string => (string) $gtin)
                    ->all());
            }
        }

        $lineGtins = $record->scanLines()
            ->with('epc:id,gtin14')
            ->get()
            ->pluck('epc.gtin14')
            ->filter(fn ($gtin): bool => filled($gtin))
            ->map(fn ($gtin): string => (string) $gtin)
            ->all();

        return array_values(array_unique([...$gtins, ...$lineGtins]));
    }

    private function matchedAsnChipLabel(?EpcisDocument $doc, int $documentId): string
    {
        if (filled($doc?->asn_number)) {
            return (string) $doc->asn_number;
        }

        if (filled($doc?->customer_po)) {
            return (string) $doc->customer_po;
        }

        return 'ASN #'.$documentId;
    }

    private function setLastScan(
        string $tone,
        string $message,
        ?string $detail = null,
        ?string $href = null,
        ?Epc $epc = null,
    ): void {
        $this->lastScanTone = $tone;
        $this->lastScanMessage = $message;
        $this->lastScanDetail = $detail;
        $this->lastScanHref = $href;
        $this->lastScanEpcId = $epc?->getKey();
        $this->lastScanContextLinks = $epc !== null
            ? array_values(array_filter(
                app(EpcContextLinks::class)->forEpc($epc, AssetTrackingUrl::scanForEpc($epc), auth()->id()),
                fn (array $link): bool => ($link['key'] ?? null) !== 'open_receive',
            ))
            : [];
    }

    private function identifierFor(?Epc $epc): ?string
    {
        if ($epc === null) {
            return null;
        }

        if (filled($epc->sscc18)) {
            return $epc->sscc18;
        }

        if (filled($epc->gtin14)) {
            return $epc->gtin14.(filled($epc->serial_number) ? ' / '.$epc->serial_number : '');
        }

        return null;
    }

    public function canCancelReceiving(): bool
    {
        /** @var ReceivingSession $record */
        $record = $this->getRecord();

        return $record->canCancel();
    }

    public function canHardDeleteReceiving(): bool
    {
        /** @var ReceivingSession $record */
        $record = $this->getRecord();

        return $record->canHardDelete();
    }

    public function canResetScans(): bool
    {
        /** @var ReceivingSession $record */
        $record = $this->getRecord();

        if ($record->receiving_events_generated_at !== null) {
            return false;
        }

        if ($record->isTransferReceive()) {
            if ($record->status !== 'completed') {
                return false;
            }

            $transfer = $record->transferringSession;

            if ($transfer === null || $transfer->receive_events_generated_at !== null) {
                return false;
            }

            return $this->confirmedCount() > 0
                || ReceivingScanLine::query()
                    ->where('receiving_session_id', $record->getKey())
                    ->where('status', 'confirmed')
                    ->exists();
        }

        if ($record->status === 'completed') {
            return false;
        }

        if (! in_array($record->status, ['open', 'in_progress'], true)) {
            return false;
        }

        if ($record->isScanFirst()) {
            return $this->confirmedCount() > 0
                || ReceivingScanLine::query()
                    ->where('receiving_session_id', $record->getKey())
                    ->exists();
        }

        if ((int) $record->confirmed_parent_count > 0 || (int) $record->confirmed_child_count > 0) {
            return true;
        }

        return ReceivingScanLine::query()
            ->where('receiving_session_id', $record->getKey())
            ->where(function ($query): void {
                $query->whereIn('status', ['confirmed', 'unexpected'])
                    ->orWhere('line_role', 'child');
            })
            ->exists();
    }

    public function canPrintReceivingLpnLabel(): bool
    {
        if (! TenantFeatures::forTenant(tenant())->supportsSsccLabeling()) {
            return false;
        }

        /** @var ReceivingSession $session */
        $session = $this->getRecord();

        if ($session->epcis_document_id === null || $session->document === null) {
            return false;
        }

        if (! in_array($session->document->status, ['parsed', 'validated'], true)) {
            return false;
        }

        if (blank(TenantSsccSettings::resolve()['company_prefix'])) {
            return false;
        }

        return LabelPrinter::query()
            ->where('enabled', true)
            ->where('is_default', true)
            ->exists();
    }

    public function desktopReceiveUrl(array $parameters = []): string
    {
        if ($scan = request()->query('scan')) {
            $parameters['scan'] = (string) $scan;
        }

        return ReceiveLayout::desktopUrl($this->getRecord(), $parameters);
    }

    public function floorReceiveUrl(array $parameters = []): string
    {
        if ($scan = request()->query('scan')) {
            $parameters['scan'] = (string) $scan;
        }

        return ReceiveLayout::floorUrl($this->getRecord(), $parameters);
    }

    public function receivingIssuesUrl(): ?string
    {
        if (! $this->isCompleted() || ! ReceivingIssues::canAccess()) {
            return null;
        }

        return ReceivingIssues::urlForSession((int) $this->getRecord()->getKey());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reportReceivingIssues')
                ->label('Report receiving issues')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('warning')
                ->visible(fn (): bool => $this->receivingIssuesUrl() !== null)
                ->url(fn (): ?string => $this->receivingIssuesUrl()),
            Action::make('matchAsn')
                ->label('Match ASN')
                ->icon(Heroicon::OutlinedDocumentCheck)
                ->color('primary')
                ->visible(fn (): bool => $this->canMatchAsn())
                ->requiresConfirmation()
                ->modalHeading('Open ASN receive for matched document?')
                ->modalDescription('Opens (or resumes) an ASN receiving session for the matched inbound EPCIS document.')
                ->modalSubmitActionLabel('Open ASN receive')
                ->action(function (): void {
                    $documentId = $this->chipMatchedAsnDocumentId
                        ?? $this->getRecord()->matched_epcis_document_id;

                    if ($documentId === null) {
                        Notification::make()
                            ->title('No matched ASN')
                            ->danger()
                            ->ephemeral()->send();

                        return;
                    }

                    $document = EpcisDocument::query()->find($documentId);

                    if ($document === null) {
                        Notification::make()
                            ->title('Matched ASN not found')
                            ->danger()
                            ->ephemeral()->send();

                        return;
                    }

                    /** @var ReceivingSession $scanFirstSession */
                    $scanFirstSession = $this->getRecord();

                    try {
                        $asnSession = app(OpenReceivingSessionFromDocument::class)->handle(
                            $document,
                            $scanFirstSession->site_id,
                            auth()->id(),
                        );
                    } catch (InvalidArgumentException|DomainException $e) {
                        Notification::make()
                            ->title('Could not open ASN receive')
                            ->body($e->getMessage())
                            ->danger()
                            ->ephemeral()->send();

                        return;
                    }

                    app(SeedOnDocumentConfirmedEpcsOntoAsnSession::class)->handle(
                        $scanFirstSession,
                        $asnSession,
                        $document,
                        auth()->id(),
                    );

                    $copy = app(CopyConfirmedReceivingScansToSession::class)->handle(
                        $scanFirstSession,
                        $asnSession->fresh(),
                        auth()->id(),
                        strictManifestOnly: true,
                    );

                    // Avoid two actives in Ops Hub: cancel the scan-first session after ASN opens.
                    if ((int) $asnSession->getKey() !== (int) $scanFirstSession->getKey()) {
                        try {
                            app(CancelReceivingSession::class)->handle($scanFirstSession->fresh(), auth()->id());
                        } catch (DomainException $e) {
                            Notification::make()
                                ->title('Could not cancel scan-first session')
                                ->body($e->getMessage())
                                ->warning()
                                ->ephemeral()->send();
                        }
                    }

                    $this->notifyScanFirstCopyResult($copy);

                    $this->redirect(ReceiveLayout::sessionUrl($asnSession));
                }),
            Action::make('attachInvoice')
                ->label('Attach invoice')
                ->icon(Heroicon::OutlinedPaperClip)
                ->color('gray')
                ->visible(fn (): bool => $this->canAttachInvoice())
                ->modalHeading('Attach paper invoice')
                ->modalDescription('Stores a copy for audit. The file is not parsed as TI, EPCIS, or ASN.')
                ->modalSubmitActionLabel('Attach')
                ->schema([
                    FileUpload::make('file')
                        ->label('Invoice or packing slip')
                        ->acceptedFileTypes([
                            'application/pdf',
                            'image/jpeg',
                            'image/png',
                            'image/webp',
                            'application/octet-stream',
                        ])
                        ->rules(['file', 'max:10240'])
                        ->maxSize(10240)
                        ->required()
                        ->storeFiles(false),
                ])
                ->action(function (array $data): void {
                    $file = $data['file'] ?? null;
                    if (is_array($file)) {
                        $file = $file[0] ?? null;
                    }

                    if (! $file instanceof TemporaryUploadedFile) {
                        Notification::make()
                            ->title('Attach failed')
                            ->body('No invoice file was received.')
                            ->danger()
                            ->ephemeral()->send();

                        return;
                    }

                    $absolutePath = $file->getRealPath();
                    if (! is_string($absolutePath) || $absolutePath === '' || ! is_file($absolutePath)) {
                        Notification::make()
                            ->title('Attach failed')
                            ->body('Invoice file is missing or unreadable.')
                            ->danger()
                            ->ephemeral()->send();

                        return;
                    }

                    try {
                        app(AttachReceivingSessionInvoice::class)->handle(
                            $this->getRecord(),
                            $absolutePath,
                            $file->getClientOriginalName(),
                            auth()->id(),
                        );
                    } catch (DomainException|AuthorizationException $e) {
                        Notification::make()
                            ->title('Could not attach invoice')
                            ->body($e->getMessage())
                            ->danger()
                            ->ephemeral()->send();

                        return;
                    }

                    $this->getRecord()->refresh();

                    Notification::make()
                        ->title('Invoice attached')
                        ->body((string) $this->attachedInvoiceFilename())
                        ->success()
                        ->ephemeral()->send();
                }),
            Action::make('closeOpenTote')
                ->label('Close tote')
                ->icon(Heroicon::OutlinedArchiveBoxXMark)
                ->color('warning')
                ->visible(fn (): bool => $this->canCloseOpenTote())
                ->action(function (): void {
                    try {
                        $result = app(CloseOpenToteReceiving::class)->handle(
                            $this->getRecord()->fresh(),
                            auth()->id(),
                            unpack: $this->unpackOnComplete && $this->receivingPolicy()->canUnpackAtReceive(),
                        );
                    } catch (InvalidArgumentException|DomainException|AuthorizationException $e) {
                        Notification::make()
                            ->title('Close tote blocked')
                            ->body($e->getMessage())
                            ->danger()
                            ->ephemeral()->send();

                        return;
                    }

                    $this->getRecord()->refresh()->loadMissing(['document', 'tradingPartner', 'site', 'matchedDocument', 'transferringSession', 'activeParentEpc']);

                    Notification::make()
                        ->title($result['short_closed'] ? 'Tote closed with shortage' : 'Tote closed')
                        ->success()
                        ->ephemeral()->send();

                    $this->dispatch('focus-scan');
                    $this->dispatch('receiving-scan-lines-updated')
                        ->to(ScanLinesRelationManager::class);
                }),
            Action::make('acceptRemaining')
                ->label('Accept remaining')
                ->icon(Heroicon::OutlinedCheck)
                ->color('primary')
                ->visible(fn (): bool => $this->canAcceptRemaining())
                ->disabled(fn (): bool => ! $this->acceptRemainingEnabled())
                ->action(function (): void {
                    try {
                        $result = app(ConfirmRemainingExpectedReceivingLines::class)->handle(
                            $this->getRecord()->fresh(),
                            auth()->id(),
                            unpack: $this->unpackOnComplete && $this->receivingPolicy()->canUnpackAtReceive(),
                        );
                    } catch (InvalidArgumentException|DomainException|AuthorizationException $e) {
                        Notification::make()
                            ->title('Accept remaining blocked')
                            ->body($e->getMessage())
                            ->danger()
                            ->ephemeral()->send();

                        return;
                    }

                    $this->getRecord()->refresh()->loadMissing(['document', 'tradingPartner', 'site', 'matchedDocument', 'transferringSession', 'activeParentEpc']);

                    $confirmed = (int) ($result['confirmed'] ?? 0);
                    $skipped = (int) ($result['skipped'] ?? 0);
                    $blockers = array_values(array_filter((array) ($result['blockers'] ?? [])));

                    $notification = Notification::make()
                        ->title(sprintf('Accepted remaining (%d confirmed, %d skipped)', $confirmed, $skipped));

                    if ($blockers !== []) {
                        $notification->body(implode("\n", array_slice($blockers, 0, 5)))->warning();
                    } else {
                        $notification->success();
                    }

                    $notification->ephemeral()->send();

                    $this->dispatch('focus-scan');
                    $this->dispatch('receiving-scan-lines-updated')
                        ->to(ScanLinesRelationManager::class);
                }),
            RegulatoryCompliance::apply(
                Action::make('completeReceiving')
                    ->label('Complete receive')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (): bool => $this->canCompleteManually())
                    ->requiresConfirmation()
                    ->modalHeading(fn (): string => $this->isInboundAsn()
                        ? 'Complete ASN receive?'
                        : 'Complete scan-first receive?')
                    ->modalDescription(fn (): string => $this->isInboundAsn()
                        ? 'Marks this session complete and authors receiving EPCIS events for confirmed ASN lines.'
                        : 'Marks this session complete and authors receiving EPCIS events for confirmed scans.')
                    ->modalSubmitActionLabel('Complete')
                    ->schema(fn (): array => $this->canShowUnpackOnComplete()
                        ? [
                            Toggle::make('unpack')
                                ->label('Unpack after receive (break hierarchy)')
                                ->helperText('Leaves sealed hierarchy intact when off. Default follows floor toggle.')
                                ->default(fn (): bool => $this->unpackOnComplete),
                        ]
                        : [])
                    ->action(function (array $data): void {
                        /** @var ReceivingSession $session */
                        $session = $this->getRecord();

                        $unpack = (bool) ($data['unpack'] ?? $this->unpackOnComplete)
                            && $this->receivingPolicy()->canUnpackAtReceive();

                        try {
                            app(CompleteReceivingSession::class)->handle($session, auth()->id(), unpack: $unpack);
                        } catch (InvalidArgumentException|DomainException $e) {
                            Notification::make()
                                ->title('Complete blocked')
                                ->body($e->getMessage())
                                ->danger()
                                ->ephemeral()->send();

                            return;
                        }

                        $this->getRecord()->refresh()->loadMissing(['document', 'tradingPartner', 'site', 'matchedDocument', 'transferringSession', 'activeParentEpc']);

                        Notification::make()
                            ->title('Receiving complete')
                            ->success()
                            ->ephemeral()->send();

                        $this->dispatch('receiving-scan-lines-updated')
                            ->to(ScanLinesRelationManager::class);
                    }),
                'receiving_complete_scan_first',
                requireReason: false,
            ),
            RegulatoryCompliance::apply(
                Action::make('closeTransferWithShortage')
                    ->label('Close with shortage')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->color('warning')
                    ->visible(fn (): bool => $this->canCloseTransferWithShortage())
                    ->requiresConfirmation()
                    ->modalHeading('Close transfer receive with shortage?')
                    ->modalDescription('Marks this receive complete even though some expected lines were not scanned. Unreceived units remain in transit until received elsewhere.')
                    ->modalSubmitActionLabel('Close with shortage')
                    ->action(function (): void {
                        /** @var ReceivingSession $session */
                        $session = $this->getRecord();

                        try {
                            app(CompleteReceivingSession::class)->handle(
                                $session->fresh(),
                                auth()->id(),
                                shortClose: true,
                            );
                        } catch (InvalidArgumentException|DomainException $e) {
                            Notification::make()
                                ->title('Close blocked')
                                ->body($e->getMessage())
                                ->danger()
                                ->ephemeral()->send();

                            return;
                        }

                        $this->getRecord()->refresh()->loadMissing(['document', 'tradingPartner', 'site', 'matchedDocument', 'transferringSession', 'activeParentEpc']);

                        Notification::make()
                            ->title('Transfer receive closed')
                            ->body('Shortfall recorded; received units were attested where scanned.')
                            ->success()
                            ->ephemeral()->send();

                        $this->dispatch('receiving-scan-lines-updated')
                            ->to(ScanLinesRelationManager::class);
                    }),
                'receiving_close_transfer_shortage',
                requireReason: true,
            ),
            RegulatoryCompliance::apply(
                Action::make('retryReceiveEpcis')
                    ->label('Retry receive EPCIS')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->visible(fn (): bool => $this->canRetryReceiveEpcis())
                    ->requiresConfirmation()
                    ->modalHeading('Retry transfer receive EPCIS?')
                    ->modalDescription('Authors receiving EPCIS for scans already confirmed on this completed transfer receive. Use when completion succeeded but custody events were not generated.')
                    ->modalSubmitActionLabel('Retry EPCIS')
                    ->action(function (): void {
                        /** @var ReceivingSession $session */
                        $session = $this->getRecord();

                        try {
                            app(CompleteReceivingSession::class)->handle($session->fresh(), auth()->id());
                        } catch (InvalidArgumentException|DomainException $e) {
                            Notification::make()
                                ->title('Retry blocked')
                                ->body($e->getMessage())
                                ->danger()
                                ->ephemeral()->send();

                            return;
                        }

                        $this->getRecord()->refresh()->loadMissing(['document', 'tradingPartner', 'site', 'matchedDocument', 'transferringSession', 'activeParentEpc']);

                        $transfer = $this->getRecord()->transferringSession;
                        if ($transfer !== null && $transfer->receive_events_generated_at !== null) {
                            Notification::make()
                                ->title('Receive EPCIS authored')
                                ->success()
                                ->ephemeral()->send();
                        } else {
                            Notification::make()
                                ->title('Receive EPCIS not authored')
                                ->body('No received units to attest, or authoring is still blocked.')
                                ->warning()
                                ->ephemeral()->send();
                        }

                        $this->dispatch('receiving-scan-lines-updated')
                            ->to(ScanLinesRelationManager::class);
                    }),
                'receiving_retry_transfer_receive_epcis',
                requireReason: false,
            ),
            RegulatoryCompliance::apply(
                Action::make('unpackHierarchy')
                    ->label('Unpack hierarchy')
                    ->icon(Heroicon::OutlinedCubeTransparent)
                    ->color('warning')
                    ->visible(fn (): bool => $this->canUnpackHierarchy())
                    ->requiresConfirmation()
                    ->modalHeading('Unpack hierarchy after receive?')
                    ->modalDescription('Authors unpacking AggregationEvent DELETE(s) and closes open parent/child links for confirmed parents. Receiving ObjectEvent is not re-emitted. Leave children unchecked to unpack all.')
                    ->modalSubmitActionLabel('Unpack hierarchy')
                    ->schema(fn (): array => $this->receivingPolicy()->canUnpackAfterReceive()
                        ? [
                            CheckboxList::make('child_epc_ids')
                                ->label('Children to unpack')
                                ->helperText('Select specific open children, or leave empty to unpack all under confirmed parents.')
                                ->options(fn (): array => app(UnpackReceivingHierarchy::class)
                                    ->openChildOptionsForConfirmedParents($this->getRecord()))
                                ->columns(1)
                                ->bulkToggleable(),
                        ]
                        : [])
                    ->action(function (array $data): void {
                        /** @var ReceivingSession $session */
                        $session = $this->getRecord();

                        $selected = array_values(array_map(
                            'intval',
                            array_filter((array) ($data['child_epc_ids'] ?? [])),
                        ));

                        try {
                            $result = app(UnpackReceivingHierarchy::class)->handle(
                                $session,
                                auth()->id(),
                                $selected === [] ? null : $selected,
                            );
                        } catch (InvalidArgumentException|DomainException $e) {
                            Notification::make()
                                ->title('Unpack blocked')
                                ->body($e->getMessage())
                                ->danger()
                                ->ephemeral()->send();

                            return;
                        }

                        $this->getRecord()->refresh()->loadMissing(['document', 'tradingPartner', 'site', 'matchedDocument', 'transferringSession', 'activeParentEpc']);

                        if (! ($result['generated'] ?? false)) {
                            Notification::make()
                                ->title('Nothing to unpack')
                                ->body('No open hierarchy links remain for confirmed parents.')
                                ->warning()
                                ->ephemeral()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Hierarchy unpacked')
                            ->body(sprintf('Closed %d aggregation link(s).', (int) ($result['closed_links'] ?? 0)))
                            ->success()
                            ->ephemeral()->send();
                    }),
                'receiving_unpack_hierarchy',
                requireReason: false,
            ),
            RegulatoryCompliance::apply(
                Action::make('resetScans')
                    ->label('Reset scans')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('danger')
                    ->visible(fn (): bool => $this->canResetScans())
                    ->requiresConfirmation()
                    ->modalHeading('Reset receiving scans?')
                    ->modalDescription(fn (): string => $this->isScanFirst()
                        ? 'Clears confirmed scans for this scan-first session. This cannot undo receiving EPCIS events after completion.'
                        : 'Clears confirmed and unexpected scans for this ASN. Expected pallet/tote lines are restored. This cannot undo receiving EPCIS events after completion.')
                    ->modalSubmitActionLabel('Reset scans')
                    ->action(function (): void {
                        /** @var ReceivingSession $session */
                        $session = $this->getRecord();

                        try {
                            app(ResetReceivingSessionScans::class)->handle($session, auth()->id());
                        } catch (DomainException $e) {
                            Notification::make()
                                ->title('Reset blocked')
                                ->body($e->getMessage())
                                ->danger()
                                ->ephemeral()->send();

                            return;
                        }

                        $this->getRecord()->refresh()->loadMissing(['document', 'tradingPartner', 'site', 'matchedDocument', 'transferringSession', 'activeParentEpc']);
                        $this->scan = '';
                        $this->lastScanMessage = null;
                        $this->lastScanTone = null;
                        $this->lastScanDetail = null;
                        $this->lastScanHref = null;
                        $this->lastScanEpcId = null;
                        $this->lastScanContextLinks = [];
                        $this->highlightUnexpected = false;
                        $this->chipHasTi = null;
                        $this->chipMatchedAsnDocumentId = null;
                        $this->chipMatchedAsnLabel = null;
                        $this->chipTransferSessionId = $this->getRecord()->transferring_session_id;
                        $this->autoConfirmChildren = $this->receivingPolicy()->defaultAutoConfirmChildren();

                        Notification::make()
                            ->title('Scans reset')
                            ->body($this->isScanFirst()
                                ? 'Confirmed progress cleared.'
                                : 'Confirmed progress cleared. Expected ASN lines restored.')
                            ->success()
                            ->ephemeral()->send();

                        $this->dispatch('focus-scan');
                        $this->dispatch('receiving-scan-lines-updated')
                            ->to(ScanLinesRelationManager::class);
                    }),
                'receiving_reset_scans',
                requireReason: true,
            ),
            RegulatoryCompliance::apply(
                Action::make('cancelReceiving')
                    ->label('Cancel receive')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->visible(fn (): bool => $this->canCancelReceiving())
                    ->requiresConfirmation()
                    ->modalHeading('Cancel this receive?')
                    ->modalDescription('Marks the session cancelled and removes it from Active receives. Scan history is kept. This cannot undo receiving EPCIS after completion.')
                    ->modalSubmitActionLabel('Cancel receive')
                    ->action(function (): void {
                        /** @var ReceivingSession $session */
                        $session = $this->getRecord();

                        try {
                            app(CancelReceivingSession::class)->handle($session, auth()->id());
                        } catch (DomainException $e) {
                            Notification::make()
                                ->title('Cancel blocked')
                                ->body($e->getMessage())
                                ->danger()
                                ->ephemeral()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Receive cancelled')
                            ->success()
                            ->ephemeral()->send();

                        $this->redirect(ReceivingSessionResource::getUrl(name: 'index', panel: 'app'));
                    }),
                'receiving_cancel',
                requireReason: true,
            ),
            UnsubmittedSessionDeleteAction::forReceivingHud(
                fn (): bool => $this->canHardDeleteReceiving(),
                fn (): int => $this->confirmedCount(),
                function (): void {
                    /** @var ReceivingSession $session */
                    $session = $this->getRecord();
                    app(DeleteReceivingSession::class)->handle($session, auth()->id());
                },
                ReceivingSessionResource::getUrl(name: 'index', panel: 'app'),
            ),
            Action::make('printLpnLabel')
                ->label('Print LPN label')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->visible(fn (): bool => $this->canPrintReceivingLpnLabel())
                ->requiresConfirmation()
                ->modalHeading('Print receiving LPN label?')
                ->modalDescription('Generates one SSCC LPN label for this inbound shipment and sends it to the default label printer.')
                ->modalSubmitActionLabel('Queue print')
                ->action(function (): void {
                    /** @var ReceivingSession $session */
                    $session = $this->getRecord();
                    $documentId = (int) $session->epcis_document_id;
                    $tenantId = (string) tenant('id');

                    if ($documentId <= 0 || $tenantId === '') {
                        Notification::make()
                            ->title('Print unavailable')
                            ->body('This receiving session has no inbound EPCIS document.')
                            ->danger()
                            ->ephemeral()->send();

                        return;
                    }

                    GenerateReceivingLpnLabelJob::dispatch($documentId, $tenantId);

                    Notification::make()
                        ->title('Label print queued')
                        ->body('An LPN label is being generated and sent to your default printer.')
                        ->success()
                        ->ephemeral()->send();

                    $this->dispatch('focus-scan');
                }),
            Action::make('viewDocument')
                ->label('View document')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->document !== null)
                ->url(fn (): ?string => $this->getRecord()->document?->filamentViewUrl()),
        ];
    }

    protected function receivingSessionDesktopContent(Schema $schema): Schema
    {
        // Scan lines immediately under the HUD; Session details stay collapsed below.
        return $schema->components([
            $this->getRelationManagersContentComponent(),
            $this->getInfolistContentComponent(),
        ]);
    }

    /**
     * @param  array{copied?: int, already_confirmed?: int, skipped?: int, notes?: list<string>}  $result
     */
    private function notifyScanFirstCopyResult(array $result, string $successTitle = 'Confirmed scans copied to ASN'): void
    {
        $copied = (int) ($result['copied'] ?? 0);

        if ($copied > 0) {
            Notification::make()
                ->title($successTitle)
                ->body(sprintf('%d line(s) carried over from scan-first.', $copied))
                ->success()
                ->ephemeral()->send();
        }

        $this->notifyScanFirstCopyIssues($result, $copied > 0);
    }

    /**
     * @param  array{copied?: int, already_confirmed?: int, skipped?: int, notes?: list<string>}  $result
     */
    private function notifyScanFirstCopyIssues(array $result, bool $hadCopies = false): void
    {
        $skipped = (int) ($result['skipped'] ?? 0);
        /** @var list<string> $notes */
        $notes = array_values(array_filter((array) ($result['notes'] ?? [])));

        if ($skipped <= 0 && $notes === []) {
            return;
        }

        $bodyParts = [];
        if ($skipped > 0) {
            $bodyParts[] = sprintf('%d line(s) skipped.', $skipped);
        }
        if ($notes !== []) {
            $displayNotes = array_slice($notes, 0, 5);
            $bodyParts[] = implode("\n", $displayNotes);
            if (count($notes) > 5) {
                $bodyParts[] = sprintf('…and %d more note(s).', count($notes) - 5);
            }
        }

        Notification::make()
            ->title($hadCopies ? 'Some scans were not copied' : 'Scan-first copy had issues')
            ->body(implode("\n\n", $bodyParts))
            ->warning()
            ->ephemeral()->send();
    }
}
