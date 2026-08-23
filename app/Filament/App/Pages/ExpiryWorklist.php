<?php

namespace App\Filament\App\Pages;

use App\Models\Epcis\Epc;
use App\Models\User;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantFeatures;
use App\Support\Tracing\Gs1DualDisplay;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use UnitEnum;

class ExpiryWorklist extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Expiry worklist';

    protected static ?string $title = 'Expiry worklist';

    protected static ?int $navigationSort = 6;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected string $view = 'filament.app.pages.expiry-worklist';

    public ?int $siteId = null;

    public int $windowDays = 90;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'expiry-worklist';
    }

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsComplianceCases()
            && JobRoleAccess::allows(Permissions::NavCompliance);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->siteId = CurrentSite::id()
            ?? array_key_first(EligibleReceiveSites::options($this->authUser()));
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'On-hand serials with ILMD expiry in the next 30/60/90 days. Asset Tracking is unchanged.';
    }

    /**
     * @return array<int, string>
     */
    public function siteOptions(): array
    {
        return EligibleReceiveSites::options($this->authUser());
    }

    /**
     * @return Collection<int, Epc>
     */
    public function rows(): Collection
    {
        $siteId = $this->resolvedSiteId();
        if ($siteId === null) {
            return collect();
        }

        $window = in_array($this->windowDays, [30, 60, 90], true) ? $this->windowDays : 90;

        $today = now()->toDateString();
        $until = now()->addDays($window)->toDateString();

        return Epc::query()
            ->where('epcs.epc_type', 'sgtin')
            ->whereHas('ilmd', function ($query) use ($today, $until): void {
                $query->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '>=', $today)
                    ->whereDate('expiry_date', '<=', $until);
            })
            ->whereIn('epcs.id', app(ShippableEpcsAtSite::class)->query($siteId)->select('epcs.id'))
            ->with('ilmd')
            ->join('epc_ilmd', 'epc_ilmd.epc_id', '=', 'epcs.id')
            ->orderBy('epc_ilmd.expiry_date')
            ->orderBy('epcs.id')
            ->select('epcs.*')
            ->limit(200)
            ->get();
    }

    public function identifier(Epc $epc): string
    {
        return Gs1DualDisplay::forEpc($epc)['primary'];
    }

    public function daysLeft(Epc $epc): ?int
    {
        $date = $epc->ilmd?->expiry_date;
        if ($date === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($date->startOfDay(), false);
    }

    private function resolvedSiteId(): ?int
    {
        if ($this->siteId === null) {
            return null;
        }

        $user = $this->authUser();
        if ($user !== null && ! SiteAccess::canAccessSite($user, $this->siteId)) {
            return null;
        }

        return $this->siteId;
    }

    private function authUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
