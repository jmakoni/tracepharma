<?php

namespace App\Filament\App\Pages;

use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Dashboard\HqRollupMetrics;
use App\Support\TenantFeatures;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

class HqRollup extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'HQ rollup';

    protected static ?string $title = 'HQ rollup';

    protected static ?int $navigationSort = 4;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected string $view = 'filament.app.pages.hq-rollup';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'hq-rollup';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return TenantFeatures::forTenant(tenant())->supportsComplianceCases()
            && TenantFeatures::forTenant(tenant())->hasAnyOperations()
            && JobRoleAccess::allows(Permissions::NavCompliance)
            && $user instanceof User
            && $user->can(Permissions::SitesAccessAll);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Receive fill, exception aging, and VRS fail rate by site. Dashboard and Analytics stay as they are.';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return app(HqRollupMetrics::class)->bySite();
    }
}
