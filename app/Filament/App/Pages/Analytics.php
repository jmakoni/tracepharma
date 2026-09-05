<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource;
use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Filament\App\Resources\OutboundEpcisDocuments\OutboundEpcisDocumentResource;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Resources\Sites\SiteResource;
use App\Filament\App\Resources\TracingRequests\TracingRequestResource;
use App\Filament\App\Resources\TradingPartners\TradingPartnerResource;
use App\Filament\App\Resources\Verifications\VerificationResource;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\HidesForPharmacySimplifiedNav;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Dashboard\AnalyticsMetrics;
use App\Support\Dashboard\DashboardWidgetCatalog;
use App\Support\Dashboard\ResolveDashboardWidgets;
use App\Support\TenantFeatures;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Throwable;
use UnitEnum;

class Analytics extends Page implements HasKnowledgeBase
{
    use HidesForPharmacySimplifiedNav;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Analytics';

    protected static ?string $title = 'Analytics';

    protected static ?int $navigationSort = 3;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected string $view = 'filament.app.pages.analytics';

    public int $rangeDays = 30;

    public int|string|null $siteId = null;

    public int|string|null $tradingPartnerId = null;

    public static function canAccess(): bool
    {
        $features = TenantFeatures::forTenant(tenant());

        if (! $features->hasAnyOperations() && ! $features->supportsComplianceCases()) {
            return false;
        }

        return JobRoleAccess::isOwner()
            || JobRoleAccess::allowsAny(
                Permissions::NavCompliance,
                Permissions::NavReceive,
                Permissions::NavShip,
                Permissions::NavIntegrations,
            );
    }

    public function mount(): void
    {
        $this->rangeDays = $this->rangeDays === 7 ? 7 : 30;
        $this->normalizeSiteId();
        $this->normalizeTradingPartnerId();
    }

    public function updatedRangeDays(mixed $value): void
    {
        $this->rangeDays = ((int) $value) === 7 ? 7 : 30;
    }

    public function updatedSiteId(mixed $value): void
    {
        $this->siteId = $value === null || $value === '' ? null : (int) $value;
        $this->normalizeSiteId();
    }

    public function updatedTradingPartnerId(mixed $value): void
    {
        $this->tradingPartnerId = $value === null || $value === '' ? null : (int) $value;
        $this->normalizeTradingPartnerId();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Operational and compliance trends from sessions, cases, verifications, and documents — not raw EPCIS events.';
    }

    public function asOfLabel(): string
    {
        return now()->timezone((string) config('app.timezone'))->toDayDateTimeString();
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     data: array<string, mixed>,
     *     url: string|null,
     *     url_label: string|null
     * }>
     */
    public function widgets(): array
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return [];
        }

        $keys = ResolveDashboardWidgets::make()->forAnalyticsPage($user);
        $metrics = AnalyticsMetrics::make(
            $user,
            $this->rangeDays,
            $this->resolvedSiteId(),
            $this->resolvedTradingPartnerId(),
        );
        $widgets = [];

        foreach ($keys as $key) {
            $definition = DashboardWidgetCatalog::definition($key);
            if ($definition === null) {
                continue;
            }

            $widgets[] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'data' => $metrics->forKey($key),
                'url' => $this->drillUrl($key),
                'url_label' => $this->drillLabel($key),
            ];
        }

        return $widgets;
    }

    /**
     * @return Collection<int, Site>
     */
    public function eligibleSites(): Collection
    {
        return SiteAccess::eligibleOrganizationSitesQuery()
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @return Collection<int, TradingPartner>
     */
    public function activePartners(): Collection
    {
        return TradingPartner::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name']);
    }

    public function tracingRequestUrl(int $id): ?string
    {
        return $this->resourceViewUrl(TracingRequestResource::class, $id);
    }

    public function siteUrl(?int $id): ?string
    {
        return $id === null ? $this->resourceIndexUrl(SiteResource::class) : $this->resourceViewUrl(SiteResource::class, $id);
    }

    public function partnerUrl(int $id): ?string
    {
        return $this->resourceViewUrl(TradingPartnerResource::class, $id);
    }

    private function resolvedSiteId(): ?int
    {
        if ($this->siteId === null || $this->siteId === '') {
            return null;
        }

        return (int) $this->siteId;
    }

    private function resolvedTradingPartnerId(): ?int
    {
        if ($this->tradingPartnerId === null || $this->tradingPartnerId === '') {
            return null;
        }

        return (int) $this->tradingPartnerId;
    }

    private function normalizeSiteId(): void
    {
        $siteId = $this->resolvedSiteId();
        if ($siteId === null) {
            $this->siteId = null;

            return;
        }

        $user = auth()->user();
        if (! $user instanceof User || ! SiteAccess::canAccessSite($user, $siteId)) {
            $this->siteId = null;
        }
    }

    private function normalizeTradingPartnerId(): void
    {
        $partnerId = $this->resolvedTradingPartnerId();
        if ($partnerId === null) {
            $this->tradingPartnerId = null;

            return;
        }

        $exists = TradingPartner::query()
            ->whereKey($partnerId)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            $this->tradingPartnerId = null;
        }
    }

    private function drillUrl(string $key): ?string
    {
        return match ($key) {
            'volume_trends' => $this->resourceIndexUrl(ReceivingSessionResource::class)
                ?? $this->resourceIndexUrl(OutboundShippingSessionResource::class),
            'exception_aging' => $this->resourceIndexUrl(ExceptionResource::class),
            'tracing_sla_score' => $this->resourceIndexUrl(TracingRequestResource::class),
            'vrs_rates' => $this->resourceIndexUrl(VerificationResource::class),
            'partner_throughput' => $this->resourceIndexUrl(TradingPartnerResource::class),
            'integration_trends' => $this->resourceIndexUrl(EpcisDocumentResource::class)
                ?? $this->resourceIndexUrl(OutboundEpcisDocumentResource::class),
            'atp_expiry', 'site_comparison' => $this->resourceIndexUrl(SiteResource::class),
            default => null,
        };
    }

    private function drillLabel(string $key): ?string
    {
        return match ($key) {
            'volume_trends' => 'View sessions',
            'exception_aging' => 'View exceptions',
            'tracing_sla_score' => 'View tracing requests',
            'vrs_rates' => 'View verifications',
            'partner_throughput' => 'View partners',
            'integration_trends' => 'View documents',
            'atp_expiry' => 'View sites',
            'site_comparison' => 'View sites',
            default => null,
        };
    }

    /**
     * @param  class-string<resource>  $resource
     */
    private function resourceIndexUrl(string $resource): ?string
    {
        try {
            if (method_exists($resource, 'canAccess') && ! $resource::canAccess()) {
                return null;
            }

            $panel = Filament::getPanel('app');
            $name = $resource::getRouteBaseName($panel).'.index';

            if (! Route::has($name)) {
                return null;
            }

            return $resource::getUrl('index', panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  class-string<resource>  $resource
     */
    private function resourceViewUrl(string $resource, int $id): ?string
    {
        try {
            if (method_exists($resource, 'canAccess') && ! $resource::canAccess()) {
                return null;
            }

            return $resource::getUrl('view', ['record' => $id], panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }

    public static function getDocumentation(): array|string
    {
        return 'compliance.compliance-reports';
    }
}
