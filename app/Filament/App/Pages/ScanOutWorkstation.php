<?php

namespace App\Filament\App\Pages;

use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Filament\App\Resources\OutboundShippingSessions\Concerns\InteractsWithOutboundShippingSessionHud;
use App\Filament\App\Resources\OutboundShippingSessions\Concerns\InteractsWithOutboundShippingWizard;
use App\Filament\Notifications\Notification;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\User;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Shipping\AtpGateBypass;
use App\Support\Shipping\OutboundShippingSessionStatus;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
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

class ScanOutWorkstation extends Page implements HasKnowledgeBase
{
    use InteractsWithOutboundShippingSessionHud;
    use InteractsWithOutboundShippingWizard;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Scan Out';

    protected static ?string $title = 'Scan Out';

    protected static ?int $navigationSort = 11;

    protected static string|UnitEnum|null $navigationGroup = 'Ship';

    protected string $view = 'filament.app.pages.scan-out-workstation';

    #[Url(as: 'session')]
    public ?int $sessionId = null;

    public bool $showSitePicker = false;

    public function mount(): void
    {
        $sessionId = $this->sessionId ?? request()->integer('session');

        if ($sessionId > 0) {
            $this->loadSession((int) $sessionId);
        }
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Desktop ship desk — scan, set customer, and send from one wizard.';
    }

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

    public static function urlForSession(int $sessionId, array $parameters = []): string
    {
        $parameters['session'] = $sessionId;

        return static::getUrl($parameters, panel: 'app');
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
        return $this->openSessionsQuery()
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
            ->with(['tradingPartner', 'site', 'shipToSite', 'outboundConnection', 'epcisDocument.outboundConnection'])
            ->whereKey($this->sessionId)
            ->first();
    }

    protected function outboundShippingSession(): OutboundShippingSession
    {
        $session = $this->session();

        if ($session === null) {
            throw new \RuntimeException('No outbound shipping session loaded.');
        }

        return $session;
    }

    protected function getOutboundShippingSession(): OutboundShippingSession
    {
        return $this->outboundShippingSession();
    }

    protected function refreshOutboundShippingSession(): void
    {
        $this->refreshOutboundShippingSessionHud();
    }

    protected function afterOutboundScanConfirmed(): void
    {
        $this->hydrateWizardFromRecord();
    }

    public function statusLabel(): string
    {
        return OutboundShippingSessionStatus::label($this->session()?->status);
    }

    public function statusBadgeColor(): string
    {
        return match ($this->session()?->status) {
            'completed' => 'success',
            'in_progress' => 'info',
            'open' => 'warning',
            'cancelled' => 'gray',
            default => 'outline',
        };
    }

    public function atpOutboundGateDisabled(): bool
    {
        return AtpGateBypass::isBypassed();
    }

    /**
     * @return array<int, string>
     */
    public function shipFromSiteOptions(): array
    {
        $options = EligibleReceiveSites::organizationOptions();
        $user = auth()->user();

        if (! $user instanceof User || $user->can(Permissions::SitesAccessAll)) {
            return $options;
        }

        $allowed = SiteAccess::userSiteIds($user)->all();

        return array_filter(
            $options,
            fn (string $name, int $id): bool => in_array($id, $allowed, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    public function defaultShipFromSiteId(): ?int
    {
        return CurrentSite::preferredId(
            TenantSettings::forTenant(tenant())->defaultShipFromSiteId(),
            $this->shipFromSiteOptions(),
        );
    }

    public function beginNewShipOrder(): void
    {
        $options = $this->shipFromSiteOptions();

        if ($options === []) {
            Notification::make()
                ->title('No ship-from sites')
                ->body('You do not have access to any eligible ship-from sites.')
                ->danger()
                ->ephemeral()
                ->send();

            return;
        }

        if (count($options) === 1) {
            $this->openNewSession((int) array_key_first($options));

            return;
        }

        $this->showSitePicker = true;
    }

    public function cancelNewShipOrder(): void
    {
        $this->showSitePicker = false;
    }

    public function openNewSession(int $siteId): void
    {
        $this->showSitePicker = false;

        if (! array_key_exists($siteId, $this->shipFromSiteOptions())) {
            Notification::make()
                ->title('Invalid site')
                ->body('That ship-from site is not available.')
                ->danger()
                ->ephemeral()
                ->send();

            return;
        }

        try {
            $session = app(OpenOutboundShippingSession::class)->handle(
                siteId: $siteId,
                openedBy: auth()->id(),
            );
        } catch (AuthorizationException|InvalidArgumentException|DomainException $e) {
            Notification::make()
                ->title('Could not open ship order')
                ->body($e->getMessage())
                ->danger()
                ->ephemeral()
                ->send();

            return;
        }

        $this->loadSession((int) $session->getKey());
        $this->wizardStep = 1;

        Notification::make()
            ->title('Ship order opened')
            ->body('Ship from '.$session->site?->name)
            ->success()
            ->ephemeral()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startShipOrder')
                ->label('New ship order')
                ->icon(Heroicon::OutlinedPlus)
                ->color('primary')
                ->visible(fn (): bool => $this->sessionId === null && ! $this->showSitePicker)
                ->action(fn (): mixed => $this->beginNewShipOrder()),
            $this->sendShipmentAction(),
        ];
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
                ->ephemeral()
                ->send();

            $this->clearSession();

            return;
        }

        $user = auth()->user();
        if ($user instanceof User && $session->site_id !== null) {
            try {
                SiteAccess::assertCanAccessSite($user, (int) $session->site_id);
            } catch (AuthorizationException $e) {
                Notification::make()
                    ->title('Access denied')
                    ->body($e->getMessage())
                    ->danger()
                    ->ephemeral()
                    ->send();

                $this->clearSession();

                return;
            }
        }

        $this->sessionId = (int) $session->getKey();
        $this->showSitePicker = false;
        $this->scan = '';
        $this->lastScanMessage = null;
        $this->lastScanTone = null;
        $this->loadOutboundShippingSessionRelations();
        $this->hydrateWizardFromRecord();

        if (request()->query('scan')) {
            $this->wizardStep = 1;
        }
    }

    private function clearSession(): void
    {
        $this->sessionId = null;
        $this->showSitePicker = false;
        $this->scan = '';
        $this->lastScanMessage = null;
        $this->lastScanTone = null;
        $this->wizardStep = 1;
    }

    /**
     * @return Builder<OutboundShippingSession>
     */
    private function openSessionsQuery(): Builder
    {
        return $this->sessionsQuery()
            ->whereIn('status', ['open', 'in_progress']);
    }

    /**
     * @return Builder<OutboundShippingSession>
     */
    private function sessionsQuery(): Builder
    {
        $query = OutboundShippingSession::query()
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

    public static function getDocumentation(): array|string
    {
        return 'workflows.outbound-shipping';
    }
}
