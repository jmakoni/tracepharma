<?php

namespace App\Filament\App\Pages;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Actions\Shipping\CompleteOutboundShippingSession;
use App\Actions\Shipping\ConfirmOutboundShippingScan;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Epcis\Epc;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Gs1\ElementString;
use App\Support\Recalls\OpenRecallFlag;
use App\Support\Shipping\OutboundShippingSessionStatus;
use App\Support\TenantFeatures;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use UnitEnum;

class ScanOutWorkstation extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Scan Out';

    protected static ?string $title = 'Scan Out';

    protected static ?int $navigationSort = 11;

    protected static string|UnitEnum|null $navigationGroup = 'Ship';

    protected string $view = 'filament.app.pages.scan-out-workstation';

    #[Url(as: 'session')]
    public ?int $sessionId = null;

    public string $scan = '';

    public ?string $lastScanMessage = null;

    /** @var 'ok'|'warn'|'error'|null */
    public ?string $lastScanTone = null;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'scan-out';
    }

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations()
            && JobRoleAccess::allows(Permissions::NavShip);
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
        return 'Desktop ship desk. Same ship orders as Ship Order — later we keep one screen.';
    }

    public function selectSession(int|string|null $sessionId): void
    {
        $id = (int) $sessionId;

        if ($id <= 0) {
            $this->clearSession();

            return;
        }

        $this->loadSession($id);
    }

    /**
     * @return Collection<int, OutboundShippingSession>
     */
    public function openSessions(): Collection
    {
        return $this->sessionsQuery()
            ->with(['tradingPartner', 'site'])
            ->limit(50)
            ->get();
    }

    public function session(): ?OutboundShippingSession
    {
        if ($this->sessionId === null) {
            return null;
        }

        return $this->sessionsQuery()
            ->with(['tradingPartner', 'site', 'shipToSite'])
            ->whereKey($this->sessionId)
            ->first();
    }

    public function statusLabel(): string
    {
        return OutboundShippingSessionStatus::label($this->session()?->status);
    }

    public function confirmedLineCount(): int
    {
        if ($this->sessionId === null) {
            return 0;
        }

        return OutboundShippingScanLine::query()
            ->where('outbound_shipping_session_id', $this->sessionId)
            ->where('status', 'confirmed')
            ->count();
    }

    public function confirmScanAction(): Action
    {
        return Action::make('confirmScan')
            ->label('Confirm')
            ->action(function (): void {
                $session = $this->session();
                if ($session === null) {
                    $this->flashScan('error', 'Open a ship order first.');

                    return;
                }

                if (! $session->canScan()) {
                    $this->flashScan('error', 'This ship order cannot accept more scans.');

                    return;
                }

                $scan = ElementString::normalize(trim($this->scan));
                $this->scan = $scan;

                if ($scan === '') {
                    $this->flashScan('error', 'Scan an SSCC or SGTIN to ship.');

                    return;
                }

                if (! $this->assertSessionSiteAccess($session)) {
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
                    $result = app(ConfirmOutboundShippingScan::class)->handle(
                        $session,
                        $scan,
                        auth()->id(),
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

                $this->flashScan('ok', (string) $result['message']);
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startShipOrder')
                ->label('New ship order')
                ->icon(Heroicon::OutlinedPlus)
                ->color('primary')
                ->visible(fn (): bool => $this->sessionId === null)
                ->action(function (): void {
                    try {
                        $session = app(OpenOutboundShippingSession::class)->handle(
                            openedBy: auth()->id(),
                        );
                    } catch (InvalidArgumentException|DomainException $e) {
                        Notification::make()
                            ->title('Could not open ship order')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->sessionId = (int) $session->getKey();
                    $this->lastScanMessage = null;
                    $this->lastScanTone = null;
                    $this->scan = '';
                }),
            RegulatoryCompliance::apply(
                Action::make('sendShipment')
                    ->label('Send shipment')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->color('success')
                    ->visible(fn (): bool => $this->session()?->canSend() ?? false)
                    ->disabled(fn (): bool => $this->sendIsMissingRequiredRefs())
                    ->requiresConfirmation()
                    ->modalHeading('Send this shipment?')
                    ->modalDescription('Authors the shipping EPCIS document and schedules outbound transmission to the partner.')
                    ->modalSubmitActionLabel('Send shipment')
                    ->action(function (): void {
                        $session = $this->session();
                        if ($session === null) {
                            return;
                        }

                        if (! $this->assertSessionSiteAccess($session)) {
                            return;
                        }

                        try {
                            app(CompleteOutboundShippingSession::class)->handle($session, auth()->id());
                        } catch (AuthorizationException|InvalidArgumentException|DomainException $e) {
                            $this->flashScan('error', $e->getMessage());
                            Notification::make()
                                ->title('Send blocked')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        $this->flashScan('ok', 'Shipment sent');
                        Notification::make()
                            ->title('Shipment sent')
                            ->success()
                            ->send();
                    }),
                'outbound_shipping_send',
                requireReason: false,
            ),
        ];
    }

    private function sendIsMissingRequiredRefs(): bool
    {
        $session = $this->session();
        if ($session === null) {
            return true;
        }

        return blank($session->asn_number)
            || (blank($session->customer_po) && blank($session->invoice_number))
            || ! $session->dscsa_affirm;
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
                ->send();

            $this->clearSession();

            return;
        }

        $this->sessionId = (int) $session->getKey();
        $this->scan = '';
    }

    private function clearSession(): void
    {
        $this->sessionId = null;
        $this->scan = '';
        $this->lastScanMessage = null;
        $this->lastScanTone = null;
    }

    /**
     * @return Builder<OutboundShippingSession>
     */
    private function sessionsQuery(): Builder
    {
        $query = OutboundShippingSession::query()
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

    private function assertSessionSiteAccess(OutboundShippingSession $session): bool
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
}
