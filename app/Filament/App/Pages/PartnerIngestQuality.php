<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Integrations\PartnerIngestQualityMetrics;
use App\Support\TenantFeatures;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use UnitEnum;

class PartnerIngestQuality extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Partner data quality';

    protected static ?string $title = 'Partner data quality';

    protected static ?int $navigationSort = 16;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected string $view = 'filament.app.pages.partner-ingest-quality';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'partner-ingest-quality';
    }

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsInboundIntegrations()
            && JobRoleAccess::allowsAny(
                Permissions::NavCompliance,
                Permissions::NavIntegrations,
            );
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Inbound ingest exception counts by trading partner (7d / 30d). Not clean-data certified / not TraceReady.';
    }

    /**
     * @return Collection<int, array{trading_partner_id: int, partner_name: string, exceptions_7d: int, exceptions_30d: int}>
     */
    public function partnerRows(): Collection
    {
        return app(PartnerIngestQualityMetrics::class)->rows();
    }
}
