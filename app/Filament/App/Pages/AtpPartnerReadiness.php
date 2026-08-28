<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Compliance\ComplianceAlertMetrics;
use App\Support\TenantFeatures;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use UnitEnum;

class AtpPartnerReadiness extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'ATP readiness';

    protected static ?string $title = 'Partner ATP readiness';

    protected static ?int $navigationSort = 15;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected string $view = 'filament.app.pages.atp-partner-readiness';

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsMasterData()
            && JobRoleAccess::allows(Permissions::NavCompliance);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Upstream partner facility licences for your organization jurisdictions.';
    }

    /**
     * @return Collection<int, array{partner: string, site: string, status: string, detail: string}>
     */
    public function partnerAtpRows(): Collection
    {
        return app(ComplianceAlertMetrics::class)->partnerAtpRows();
    }
}
