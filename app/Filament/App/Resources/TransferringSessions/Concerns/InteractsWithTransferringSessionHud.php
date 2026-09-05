<?php

namespace App\Filament\App\Resources\TransferringSessions\Concerns;

use App\Actions\Receiving\OpenTransferReceivingSession;
use App\Actions\Transferring\CompleteTransferringSession;
use App\Actions\Transferring\ConfirmTransferringScan;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Resources\TransferringSessions\RelationManagers\ScanLinesRelationManager;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Epcis\Epc;
use App\Models\Receiving\ReceivingSession;
use App\Models\Transferring\TransferringSession;
use App\Support\Fda\ScheduledProductPresence;
use App\Support\Fda\ScheduledSessionChip;
use App\Support\Gs1\ElementString;
use App\Support\TenantSettings;
use App\Support\Tracing\AssetTrackingUrl;
use App\Support\Tracing\EpcContextLinks;
use App\Support\Transferring\TransferLayout;
use App\Support\Transferring\TransferringSessionStatus;
use DomainException;
use Filament\Actions\Action;
use App\Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use Livewire\Attributes\Locked;

trait InteractsWithTransferringSessionHud
{
    public ?string $scan = '';

    public ?string $lastScanMessage = null;

    /** @var 'ok'|'warn'|'error'|null */
    public ?string $lastScanTone = null;

    public ?string $lastScanDetail = null;

    public ?string $lastScanHref = null;

    public ?int $lastScanEpcId = null;

    /** @var list<array{key: string, label: string, url: ?string, opens: bool}> */
    public array $lastScanContextLinks = [];

    #[Locked]
    public bool $confirmScanInFlight = false;

    public ?string $chipDeaSchedule = null;

    public ?bool $chipDeaMissingParty = null;

    public ?string $chipDeaLabel = null;

    public ?string $chipDeaColor = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->loadMissing(['fromSite', 'toSite', 'transferDocument', 'receivingSession']);
        $this->hydrateDeaScheduleChip();

        if ($scan = request()->query('scan')) {
            $this->scan = (string) $scan;
        }
    }

    public function statusLabel(): string
    {
        return TransferringSessionStatus::label($this->getRecord()->status);
    }

    public function isOpen(): bool
    {
        return $this->getRecord()->status === 'open';
    }

    public function isInTransit(): bool
    {
        return $this->getRecord()->status === 'in_transit';
    }

    public function isCompleted(): bool
    {
        return $this->getRecord()->status === 'completed';
    }

    public function canScan(): bool
    {
        return $this->isOpen();
    }

    public function receivingSession(): ?ReceivingSession
    {
        return $this->getRecord()->receivingSession;
    }

    public function statusBadgeColor(): string
    {
        return match ($this->getRecord()->status) {
            'completed' => 'success',
            'in_transit' => 'info',
            'open' => 'warning',
            default => 'outline',
        };
    }

    public function confirmedCount(): int
    {
        return (int) $this->getRecord()->confirmed_count;
    }

    public function desktopTransferUrl(array $parameters = []): string
    {
        if ($scan = request()->query('scan')) {
            $parameters['scan'] = (string) $scan;
        }

        return TransferLayout::desktopUrl($this->getRecord(), $parameters);
    }

    public function floorTransferUrl(array $parameters = []): string
    {
        if ($scan = request()->query('scan')) {
            $parameters['scan'] = (string) $scan;
        }

        return TransferLayout::floorUrl($this->getRecord(), $parameters);
    }

    public function confirmScanAction(): Action
    {
        return Action::make('confirmScan')
            ->label('Confirm')
            ->action(function (): void {
                if ($this->confirmScanInFlight) {
                    return;
                }

                $this->confirmScanInFlight = true;

                try {
                    /** @var TransferringSession $session */
                    $session = $this->getRecord();

                    if ($session->status === 'completed') {
                        $this->setLastScan('error', 'This transfer is already complete.');

                        Notification::make()
                            ->title('Already complete')
                            ->danger()
                            ->ephemeral()->send();

                        $this->dispatch('scan-result', tone: 'error');

                        return;
                    }

                    if ($session->status === 'in_transit') {
                        $this->setLastScan('error', 'Receive this transfer from the Receive workstation.');

                        Notification::make()
                            ->title('Receive at destination')
                            ->body('Use Receive at destination to open the transfer receive session.')
                            ->warning()
                            ->ephemeral()->send();

                        $this->dispatch('scan-result', tone: 'error');

                        return;
                    }

                    $scan = ElementString::normalize(trim((string) $this->scan));
                    $this->scan = $scan;

                    if ($scan === '') {
                        $this->setLastScan('error', 'Scan an SSCC or SGTIN to confirm.');

                        Notification::make()
                            ->title('Scan required')
                            ->danger()
                            ->ephemeral()->send();

                        $this->dispatch('focus-scan');
                        $this->dispatch('scan-result', tone: 'error');

                        return;
                    }

                    $result = app(ConfirmTransferringScan::class)->handle(
                        $session,
                        $scan,
                        auth()->id(),
                    );

                    $tone = match ($result['effect']) {
                        'already_confirmed' => 'warn',
                        'confirmed' => 'ok',
                        'double_transfer' => 'error',
                        default => 'error',
                    };

                    $this->scan = '';
                    $this->getRecord()->refresh()->loadMissing(['fromSite', 'toSite', 'transferDocument', 'receivingSession']);
                    $this->hydrateDeaScheduleChip();

                    $this->setLastScan(
                        $tone,
                        $result['message'],
                        $this->identifierFor($result['epc']),
                        AssetTrackingUrl::forEpc($result['epc'] ?? null),
                        $result['epc'] ?? null,
                    );

                    $notification = Notification::make()->title($result['message']);

                    match ($tone) {
                        'ok' => $notification->success(),
                        'warn' => $notification->warning(),
                        default => $notification->danger(),
                    };

                    $notification->ephemeral()->send();

                    $this->dispatch('focus-scan');
                    $this->dispatch('scan-result', tone: $tone);
                    $this->dispatch('transferring-scan-lines-updated')
                        ->to(ScanLinesRelationManager::class);
                } finally {
                    $this->confirmScanInFlight = false;
                }
            });
    }

    public function stageScan(?string $raw = null): void
    {
        if ($this->confirmScanInFlight) {
            return;
        }

        if ($raw !== null) {
            $this->scan = ElementString::normalize(trim($raw));
        }

        $this->mountAction('confirmScan');
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
                fn (array $link): bool => ($link['key'] ?? null) !== 'open_transfer',
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

    protected function getHeaderActions(): array
    {
        return [
            RegulatoryCompliance::apply(
                Action::make('completeTransfer')
                    ->label('Ship transfer')
                    ->icon(Heroicon::OutlinedTruck)
                    ->color('success')
                    ->visible(fn (): bool => $this->isOpen())
                    ->requiresConfirmation()
                    ->modalHeading('Ship this transfer?')
                    ->modalDescription('Marks the transfer in transit and authors the shipping EPCIS event. Destination receive opens from Receive at destination.')
                    ->modalSubmitActionLabel('Mark shipped')
                    ->action(function (): void {
                        /** @var TransferringSession $session */
                        $session = $this->getRecord();

                        try {
                            app(CompleteTransferringSession::class)->handle($session, auth()->id());
                        } catch (InvalidArgumentException|DomainException $e) {
                            Notification::make()
                                ->title('Ship blocked')
                                ->body($e->getMessage())
                                ->danger()
                                ->ephemeral()->send();

                            return;
                        }

                        $this->getRecord()->refresh()->loadMissing(['fromSite', 'toSite', 'transferDocument', 'receivingSession']);
                        $this->scan = '';
                        $this->lastScanMessage = null;
                        $this->lastScanTone = null;
                        $this->lastScanDetail = null;
                        $this->lastScanHref = null;
                        $this->lastScanEpcId = null;
                        $this->lastScanContextLinks = [];

                        $this->dispatch('transferring-scan-lines-updated')
                            ->to(ScanLinesRelationManager::class);

                        if (! ReceivingSessionResource::canAccess()) {
                            Notification::make()
                                ->title('Transfer shipped')
                                ->body('Shipping EPCIS event authored. Open Receive at destination when goods arrive.')
                                ->success()
                                ->ephemeral()->send();

                            return;
                        }

                        if (! TenantSettings::forTenant(tenant())->autoOpenReceiveAfterTransferShip()) {
                            Notification::make()
                                ->title('Transfer shipped')
                                ->body('Shipping EPCIS event authored. Open Receive at destination to confirm arrival.')
                                ->success()
                                ->ephemeral()->send();

                            return;
                        }

                        try {
                            $receiving = app(OpenTransferReceivingSession::class)->handle(
                                $this->getRecord(),
                                auth()->id(),
                            );
                        } catch (InvalidArgumentException|DomainException $e) {
                            Notification::make()
                                ->title('Transfer shipped')
                                ->body('Shipping EPCIS event authored. Open Receive at destination to confirm arrival. '.$e->getMessage())
                                ->warning()
                                ->ephemeral()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Transfer shipped')
                            ->body('Shipping EPCIS event authored. Continue at the destination receive session.')
                            ->success()
                            ->ephemeral()->send();

                        $this->redirect(ReceivingSessionResource::getUrl('view', [
                            'record' => $receiving,
                        ], panel: 'app'));
                    }),
                'transferring_ship',
                requireReason: false,
            ),
        ];
    }

    private function hydrateDeaScheduleChip(): void
    {
        /** @var TransferringSession $session */
        $session = $this->getRecord();
        $gtins = $session->scanLines()
            ->with('epc:id,gtin14')
            ->get()
            ->pluck('epc.gtin14')
            ->filter(fn ($gtin): bool => filled($gtin))
            ->map(fn ($gtin): string => (string) $gtin)
            ->unique()
            ->values()
            ->all();

        $presence = ScheduledProductPresence::forGtins($gtins);
        $highest = $presence['highest'];

        $missing = false;
        if ($presence['has_scheduled']) {
            $missing = ! ScheduledSessionChip::siteHasDea($session->toSite);
        }

        $this->chipDeaSchedule = $highest;
        $this->chipDeaMissingParty = $presence['has_scheduled'] ? $missing : null;
        $this->chipDeaLabel = ScheduledSessionChip::label($highest, $missing, 'No DEA on destination');
        $this->chipDeaColor = ScheduledSessionChip::badgeColor($highest);
    }
}
