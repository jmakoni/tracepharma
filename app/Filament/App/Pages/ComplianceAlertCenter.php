<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Compliance\ComplianceAlertMetrics;
use App\Support\TenantFeatures;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use UnitEnum;

class ComplianceAlertCenter extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'Alert center';

    protected static ?string $title = 'Compliance alert center';

    protected static ?int $navigationSort = 2;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected string $view = 'filament.app.pages.compliance-alert-center';

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsComplianceAlertCenter()
            && JobRoleAccess::allowsAny(
                Permissions::NavExceptions,
                Permissions::NavCompliance,
            );
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'ATP/expiry and exception signals for compliance; integration failures for Support Engineers.';
    }

    /**
     * @return list<array{severity: string, title: string, detail: string}>
     */
    public function alerts(): array
    {
        return app(ComplianceAlertMetrics::class)->alerts(auth()->user());
    }

    /**
     * @return Collection<int, array{partner: string, site: string, status: string, detail: string}>
     */
    public function partnerAtpRows(): Collection
    {
        return app(ComplianceAlertMetrics::class)->partnerAtpRows();
    }

    public static function getDocumentation(): array|string
    {
        return 'compliance.compliance-alerts';
    }
}
