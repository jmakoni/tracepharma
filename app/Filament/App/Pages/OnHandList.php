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
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use UnitEnum;

class OnHandList extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'On-hand list';

    protected static ?string $title = 'On-hand list';

    protected static ?int $navigationSort = 12;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected string $view = 'filament.app.pages.on-hand-list';

    public ?int $siteId = null;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'on-hand';
    }

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->hasAnyOperations()
            && JobRoleAccess::allowsAny(Permissions::NavReceive, Permissions::NavShip);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return TenantFeatures::forTenant(tenant())->showsWholesaleOperationsNav()
            && static::canAccess();
    }

    public function mount(): void
    {
        $this->siteId = CurrentSite::id()
            ?? array_key_first(EligibleReceiveSites::options($this->authUser()));
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Last-seen custody at this site. Not a second inventory system. Asset Tracking is unchanged.';
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

        return app(ShippableEpcsAtSite::class)->query($siteId)
            ->with('ilmd')
            ->orderBy('epcs.id')
            ->limit(200)
            ->get();
    }

    public function identifier(Epc $epc): string
    {
        return Gs1DualDisplay::forEpc($epc)['primary'];
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

    public static function getDocumentation(): array|string
    {
        return 'operations.on-hand-and-unpacked';
    }
}
