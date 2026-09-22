<?php

namespace App\Filament\App\Pages;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\DeleteReceivingSession;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Actions\Receiving\UnconfirmReceivingScanLine;
use App\Filament\Notifications\Notification;
use App\Filament\Support\Floor\UnsubmittedSessionDeleteAction;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Epcis\Epc;
use App\Models\Receiving\InboundShipment;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Floor\UnsubmittedSessionDelete;
use App\Support\Gs1\ElementString;
use App\Support\Recalls\OpenRecallFlag;
use App\Support\Receiving\ExpectedInboundOrderHeader;
use App\Support\Receiving\ReceiveLayout;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\Receiving\ReceivingSessionCompleteCopy;
use App\Support\Receiving\ReceivingSessionProgress;
use App\Support\Receiving\ReceivingSessionStatus;
use App\Support\Receiving\ResolveLotLevelReceiveScan;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use App\Support\Tracing\Gs1DualDisplay;
use DomainException;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use UnitEnum;

class ScanInWorkstation extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'Scan In';

    protected static ?string $title = 'Scan In';

    protected static ?int $navigationSort = 2;

    protected static string|UnitEnum|null $navigationGroup = 'Receiving';

    protected string $view = 'filament.app.pages.scan-in-workstation';

    #[Url(as: 'session')]
    public ?int $sessionId = null;

    public string $scan = '';

    public ?string $lastScanMessage = null;

    /** @var 'ok'|'warn'|'error'|null */
    public ?string $lastScanTone = null;

    /** Session-scoped seal ack after "Seal intact?" confirmation. */
    public bool $sessionSealAcknowledged = false;

    public function shouldAskSealIntact(): bool
    {
        if (! TenantSettings::forTenant(tenant())->requireSealQuestion()) {
            return false;
        }

        return ReceivingPolicy::forTenant(tenant())->defaultAutoConfirmChildren();
    }

    public function sealAcknowledgedForConfirm(): bool
    {
        return ! $this->shouldAskSealIntact() || $this->sessionSealAcknowledged;
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return 'scan-in';
    }

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsReceiving()
            && JobRoleAccess::allows(Permissions::NavReceive);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function urlForSession(int $sessionId): string
    {
        return static::getUrl(['session' => $sessionId], panel: 'app');
    }

    public function mount(): void
    {
        $sessionId = $this->sessionId ?? request()->integer('session');

        if ($sessionId > 0) {
            $this->loadSession((int) $sessionId);
        }
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Desktop receive desk. Same sessions as Receive — later we keep one screen.';
    }

    public function selectSession(int|string|null $sessionId): void
    {
        $id = (int) $sessionId;

        if ($id <= 0) {
            $this->clearSession();

            return;
        }

        $this->loadSession($id);

        if ($this->sessionId !== null && $this->preferFloorHud()) {
            $this->redirect(ReceiveLayout::floorUrl($this->sessionId));
        }
    }

    /**
     * @return Collection<int, ReceivingSession>
     */
    public function openSessions(): Collection
    {
        return $this->sessionsQuery()
            ->with(['tradingPartner', 'site'])
            ->limit(50)
            ->get();
    }

    public function session(): ?ReceivingSession
    {
        if ($this->sessionId === null) {
            return null;
        }

        return $this->sessionsQuery()
            ->with(['document', 'document.inboundShipment', 'inboundShipment', 'tradingPartner', 'site'])
            ->whereKey($this->sessionId)
            ->first();
    }

    public function kindBadgeLabel(): string
    {
        $session = $this->session();

        return $session?->session_kind?->badgeLabel() ?? 'Receive';
    }

    public function edgeModeChipLabel(): string
    {
        return ReceivingPolicy::forTenant(tenant())->edgeMode()->chipLabel();
    }

    public function statusLabel(): string
    {
        return ReceivingSessionStatus::label($this->session()?->status);
    }

    public function confirmedLineCount(): int
    {
        if ($this->sessionId === null) {
            return 0;
        }

        return ReceivingScanLine::query()
            ->where('receiving_session_id', $this->sessionId)
            ->where('status', 'confirmed')
            ->count();
    }

    public function sessionProgress(): ?ReceivingSessionProgress
    {
        $session = $this->session();

        if ($session === null) {
            return null;
        }

        return ReceivingSessionProgress::for($session, ReceivingPolicy::forTenant(tenant()));
    }

    public function expectedOrderShipment(): ?InboundShipment
    {
        $session = $this->session();

        return $session !== null ? ExpectedInboundOrderHeader::shipmentFor($session) : null;
    }

    /**
     * @return array{
     *     po: ?string,
     *     asn: ?string,
     *     status: string,
     *     parents_confirmed: int,
     *     parents_expected: int,
     *     eaches_confirmed: int,
     *     eaches_expected: int
     * }|null
     */
    public function expectedOrderHeader(): ?array
    {
        $session = $this->session();

        return $session !== null ? ExpectedInboundOrderHeader::forSession($session) : null;
    }

    public function contextSiteLabel(): string
    {
        $session = $this->session();

        return $session?->site?->name
            ?? $session?->tradingPartner?->name
            ?? 'No site selected';
    }

    public function promptCopy(): array
    {
        $session = $this->session();
        $copy = ReceivingPolicy::forTenant(tenant())->promptCopy($session);

        if ($session !== null) {
            $complete = ReceivingSessionCompleteCopy::for($session);
            $copy['completeTitle'] = $complete['title'];
            $copy['completeBody'] = $complete['body'];
            $copy['documentComplete'] = $complete['document_complete'];
        }

        return $copy;
    }

    public function confirmScanAction(): Action
    {
        return Action::make('confirmScan')
            ->label('Confirm')
            ->requiresConfirmation(fn (): bool => $this->shouldAskSealIntact())
            ->modalHeading('Seal intact?')
            ->modalDescription('Confirm the outer seal is intact before receiving this sealed hierarchy.')
            ->modalSubmitActionLabel('Seal intact')
            ->action(function (): void {
                $this->sessionSealAcknowledged = true;
                $session = $this->session();
                if ($session === null) {
                    $this->flashScan('error', 'Open a receive session first.');

                    return;
                }

                if ($session->status === 'completed') {
                    $this->flashScan('error', 'Receiving is already complete for this session.');

                    return;
                }

                $scan = ElementString::normalize(trim($this->scan));
                $this->scan = $scan;

                if ($scan === '') {
                    $this->flashScan('error', 'Scan an SSCC or SGTIN to confirm.');

                    return;
                }

                if (! $this->assertSessionSiteAccess($session)) {
                    return;
                }

                try {
                    $scan = app(ResolveLotLevelReceiveScan::class)->handle($session, $scan);
                } catch (DomainException $e) {
                    $this->flashScan('error', $e->getMessage());

                    return;
                }

                $resolved = app(ResolveEpcFromScan::class)->handle($scan);
                $epc = $resolved['epc'] ?? null;
                if ($epc instanceof Epc) {
                    $recallBlock = app(OpenRecallFlag::class)->blocks($epc);
                    if ($recallBlock !== null) {
                        $this->flashScan('error', $recallBlock);

                        return;
                    }
                }

                try {
                    $result = app(ConfirmReceivingScan::class)->handle(
                        $session,
                        $scan,
                        auth()->id(),
                        ReceivingPolicy::forTenant(tenant())->defaultAutoConfirmChildren(),
                        unpack: ReceivingPolicy::forTenant(tenant())->canUnpackAtReceive(),
                        sealAcknowledged: $this->sealAcknowledgedForConfirm(),
                    );
                } catch (InvalidArgumentException|DomainException $e) {
                    $this->flashScan('error', $e->getMessage());

                    return;
                }

                $this->scan = '';
                $this->sessionId = (int) $session->getKey();

                if (! ($result['ok'] ?? false)) {
                    $this->flashScan('error', (string) ($result['message'] ?? 'Scan not confirmed.'));

                    return;
                }

                $tone = filled($result['ti_warning'] ?? null) ? 'warn' : 'ok';
                $this->flashScan($tone, (string) $result['message']);
            });
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            Action::make('startScanFirst')
                ->label('Start scan-first')
                ->icon(Heroicon::OutlinedPlus)
                ->color('primary')
                ->visible(fn (): bool => $this->sessionId === null)
                ->action(function (): void {
                    $this->startScanFirstFromPicker();
                }),
            RegulatoryCompliance::apply(
                Action::make('completeReceiving')
                    ->label('Complete session')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('primary')
                    ->visible(fn (): bool => $this->canCompleteManually())
                    ->requiresConfirmation()
                    ->modalHeading('Complete scan-first receive?')
                    ->modalDescription('Marks this session complete and authors receiving EPCIS events for confirmed scans.')
                    ->modalSubmitActionLabel('Complete')
                    ->action(function (): void {
                        $session = $this->session();
                        if ($session === null) {
                            return;
                        }

                        if (! $this->assertSessionSiteAccess($session)) {
                            return;
                        }

                        try {
                            app(CompleteReceivingSession::class)->handle(
                                $session,
                                auth()->id(),
                                unpack: false,
                                shortClose: true,
                            );
                        } catch (InvalidArgumentException|DomainException $e) {
                            Notification::make()
                                ->title('Complete blocked')
                                ->body($e->getMessage())
                                ->danger()
                                ->ephemeral()->send();

                            return;
                        }

                        $session = $session->fresh() ?? $session;
                        $completeTitle = ReceivingSessionCompleteCopy::for($session)['title'];
                        $this->flashScan('ok', $completeTitle);
                        Notification::make()
                            ->title($completeTitle)
                            ->success()
                            ->ephemeral()->send();

                        $this->openNextInboundIfAvailable($session);
                    }),
                'receiving_complete_scan_first',
                requireReason: false,
            ),
            UnsubmittedSessionDeleteAction::forReceivingHud(
                fn (): bool => $this->session()?->canHardDelete() ?? false,
                function (): int {
                    $session = $this->session();

                    return $session !== null
                        ? UnsubmittedSessionDelete::confirmedScanCountReceiving($session)
                        : 0;
                },
                function (): void {
                    $session = $this->session();
                    if ($session === null) {
                        return;
                    }

                    if (! $this->assertSessionSiteAccess($session)) {
                        return;
                    }

                    app(DeleteReceivingSession::class)->handle($session, auth()->id());
                    $this->clearSession();
                },
                static::getUrl(panel: 'app'),
            ),
        ];

        return $actions;
    }

    private function openNextInboundIfAvailable(ReceivingSession $completed): void
    {
        $next = $this->sessionsQuery()
            ->when($completed->site_id !== null, fn (Builder $query) => $query->where('site_id', $completed->site_id))
            ->whereKeyNot($completed->getKey())
            ->orderBy('opened_at')
            ->orderBy('id')
            ->first();

        if ($next === null) {
            $this->clearSession();

            return;
        }

        $this->sessionId = (int) $next->getKey();
        $this->scan = '';
        Notification::make()
            ->title('Opened next receive session')
            ->success()
            ->ephemeral()->send();
    }

    private function canCompleteManually(): bool
    {
        $session = $this->session();

        if ($session === null || $session->status === 'completed') {
            return false;
        }

        return $this->confirmedLineCount() > 0;
    }

    /**
     * @return Collection<int, array{line_id: int, identifier: string, scanned_at: string, urn: string, present: bool}>
     */
    public function confirmedScanRows(): Collection
    {
        if ($this->sessionId === null) {
            return collect();
        }

        return ReceivingScanLine::query()
            ->where('receiving_session_id', $this->sessionId)
            ->where('status', 'confirmed')
            ->where(function ($query): void {
                $query->where('line_role', 'parent')
                    ->orWhere('status', 'unexpected')
                    ->orWhere(function ($child): void {
                        $child->where('line_role', 'child')
                            ->whereNull('parent_epc_id');
                    });
            })
            ->with([
                'epc:id,epc_type,gtin14,serial_number,epc_uri,sscc18,ai_00,ai_01_21',
                'epc.ilmd',
            ])
            ->orderBy('id')
            ->get()
            ->map(function (ReceivingScanLine $line): array {
                $epc = $line->epc;
                $display = $epc instanceof Epc
                    ? Gs1DualDisplay::forEpc($epc)
                    : ['gs1_barcode' => (string) ($line->scan_raw ?? '—'), 'urn' => ''];

                return [
                    'line_id' => (int) $line->getKey(),
                    'identifier' => $display['gs1_barcode'] !== '' ? $display['gs1_barcode'] : '—',
                    'scanned_at' => $line->confirmed_at?->format('Y-m-d H:i:s') ?? '—',
                    'urn' => $display['urn'] !== '' ? $display['urn'] : '—',
                    'present' => true,
                ];
            })
            ->values();
    }

    public function removeConfirmed(int $lineId): void
    {
        $session = $this->session();
        if ($session === null || $session->status === 'completed') {
            return;
        }

        $line = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->whereKey($lineId)
            ->first();

        if ($line === null) {
            $this->flashScan('error', 'Scan not found on this session.');

            return;
        }

        if ($line->status !== 'confirmed') {
            return;
        }

        try {
            app(UnconfirmReceivingScanLine::class)->handle(
                $line,
                auth()->id(),
                allowChildUnderConfirmedParent: $line->line_role === 'child',
            );
        } catch (DomainException $e) {
            $this->flashScan('error', $e->getMessage());

            return;
        }

        $this->flashScan('ok', 'Removed from this receive.');
    }

    public function startScanFirstFromPicker(): void
    {
        try {
            $session = app(OpenScanFirstReceivingSession::class)->handle(
                openedBy: auth()->id(),
            );
        } catch (InvalidArgumentException|DomainException $e) {
            Notification::make()
                ->title('Could not open scan-first')
                ->body($e->getMessage())
                ->danger()
                ->ephemeral()->send();

            return;
        }

        if ($this->preferFloorHud()) {
            $this->redirect(ReceiveLayout::floorUrl($session));

            return;
        }

        $this->sessionId = (int) $session->getKey();
        $this->lastScanMessage = null;
        $this->lastScanTone = null;
        $this->scan = '';
    }

    private function preferFloorHud(): bool
    {
        return function_exists('floorShell') && floorShell();
    }

    private function loadSession(int $sessionId): void
    {
        $session = $this->sessionsQuery()
            ->whereKey($sessionId)
            ->first();

        if ($session === null) {
            Notification::make()
                ->title('Session not found')
                ->danger()
                ->ephemeral()->send();

            $this->clearSession();

            return;
        }

        $this->sessionId = (int) $session->getKey();
        $this->scan = '';
        $this->sessionSealAcknowledged = false;
    }

    private function clearSession(): void
    {
        $this->sessionId = null;
        $this->scan = '';
        $this->lastScanMessage = null;
        $this->lastScanTone = null;
        $this->sessionSealAcknowledged = false;
    }

    /**
     * @return Builder<ReceivingSession>
     */
    private function sessionsQuery(): Builder
    {
        $query = ReceivingSession::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->latest('opened_at')
            ->latest('id');

        $user = auth()->user();

        if ($user === null) {
            return $query->whereRaw('0 = 1');
        }

        if ($user->can(Permissions::SitesAccessAll)) {
            return $query;
        }

        return $query->whereIn('site_id', SiteAccess::userSiteIds($user));
    }

    private function assertSessionSiteAccess(ReceivingSession $session): bool
    {
        $user = auth()->user();
        if (! $user instanceof User || $session->site_id === null) {
            return true;
        }

        try {
            SiteAccess::assertCanAccessSite($user, (int) $session->site_id);
        } catch (AuthorizationException $e) {
            $this->flashScan('error', $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * @param  'ok'|'warn'|'error'  $tone
     */
    private function flashScan(string $tone, string $message): void
    {
        $this->lastScanTone = $tone;
        $this->lastScanMessage = $message;
        $this->dispatch('focus-scan');
        $this->dispatch('scan-result', tone: $tone);
    }

    public static function getDocumentation(): array|string
    {
        return 'workflows.receiving';
    }
}
