<?php

namespace App\Filament\App\Pages;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Filament\Notifications\Notification;
use App\Models\Epcis\Epc;
use App\Models\User;
use App\Services\Tracing\BuildAssetTrace;
use App\Support\Auth\SiteAccess;
use App\Support\Custody\PrincipalCustody;
use App\Support\Custody\ResolveEpcLastKnownGln;
use App\Support\Gs1\ElementString;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Handheld Profile → Find: same scan → BuildAssetTrace dossier as
 * {@see AssetTracking}, rendered in floor-shell (no desktop chrome).
 */
class FloorFind extends Page
{
    protected static string $layout = 'layouts.floor-shell';

    protected string $view = 'filament.app.pages.floor-find';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * @var array<string, mixed>
     */
    protected array $extraBodyAttributes = [
        'class' => 'tp-floor-shell-page tp-floor-find-page',
    ];

    public string $scan = '';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $trace = null;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'find';
    }

    public static function canAccess(): bool
    {
        return AssetTracking::canAccess();
    }

    public function mount(): void
    {
        $scan = request()->query('scan');

        if (filled($scan)) {
            $this->scan = (string) $scan;
            $this->runTrace();
        }
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    public function desktopFindUrl(): string
    {
        $params = filled($this->scan) ? ['scan' => $this->scan] : [];

        return AssetTracking::getUrl($params, panel: 'app');
    }

    public function floorFindUrl(): string
    {
        return static::getUrl(panel: 'app');
    }

    public function runTrace(?string $raw = null): void
    {
        $builder = app(BuildAssetTrace::class);
        $scan = ElementString::normalize(trim($raw ?? (string) $this->scan));
        $this->scan = $scan;

        if ($scan === '') {
            Notification::make()
                ->title('Scan required')
                ->body('Scan an SGTIN or SSCC to find.')
                ->warning()
                ->send();

            $this->dispatch('focus-scan');

            return;
        }

        $resolved = app(ResolveEpcFromScan::class)->handle($scan);
        $epc = $resolved['epc'] ?? null;

        if ($epc instanceof Epc && ! $this->canAccessEpc($epc)) {
            $this->trace = null;

            Notification::make()
                ->title('Not authorized')
                ->body('You do not have access to this asset at its last-seen site.')
                ->danger()
                ->send();

            $this->dispatch('scan-result', tone: 'error');
            $this->dispatch('focus-scan');

            return;
        }

        $this->trace = $builder->handle($scan);

        if ($this->trace['found'] ?? false) {
            $this->dispatch('scan-result', tone: (string) ($this->trace['status_tone'] ?? 'ok'));
        } else {
            Notification::make()
                ->title('No asset found')
                ->body('No trace record for this scan.')
                ->warning()
                ->send();

            $this->dispatch('scan-result', tone: 'error');
        }

        $this->dispatch('focus-scan');
    }

    private function canAccessEpc(Epc $epc): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        $gln = app(ResolveEpcLastKnownGln::class)->forEpc((int) $epc->getKey());
        $siteId = SiteAccess::organizationSiteIdForGln($gln);

        if ($siteId !== null) {
            if (! SiteAccess::canAccessShipToSite($user, $siteId)) {
                return false;
            }

            $custody = PrincipalCustody::forTenant();
            if ($custody->isEnforced()) {
                return $custody->allowsRead(
                    $custody->activePrincipalIdForSite($siteId),
                    $epc,
                );
            }

            return true;
        }

        if (! SiteAccess::canAccessShipToSite($user, null)) {
            return false;
        }

        $custody = PrincipalCustody::forTenant();
        if ($custody->isEnforced()) {
            return $epc->principal_id !== null
                && $custody->allowsRead((int) $epc->principal_id, $epc);
        }

        return true;
    }
}
