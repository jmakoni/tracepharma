<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\BuyingGroup\BuyingGroupNetworkSnapshots;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use UnitEnum;

class AuthorizedPartnerMatrix extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?string $navigationLabel = 'Partner matrix';

    protected static ?string $title = 'Authorized partner matrix';

    protected static ?int $navigationSort = 27;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected string $view = 'filament.app.pages.authorized-partner-matrix';

    public ?string $licenseStatusFilter = null;

    public static function canAccess(): bool
    {
        $tenant = tenant();

        return TenantFeatures::forTenant($tenant)->supportsBuyingGroupNetwork()
            && TenantSettings::forTenant($tenant)->buyingGroupMemberRollupsEnabled()
            && JobRoleAccess::allows(Permissions::NavCompliance);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Member ↔ wholesaler licence snapshots from the daily rollup. Soft roster members show N/A until linked.';
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function matrixRows(): Collection
    {
        return app(BuyingGroupNetworkSnapshots::class)->partnerMatrixRows(
            statusFilter: filled($this->licenseStatusFilter) ? $this->licenseStatusFilter : null,
        );
    }

    public function updatedLicenseStatusFilter(): void
    {
        // Livewire re-render
    }

    public static function getDocumentation(): array|string
    {
        return 'operations.buying-group';
    }
}
