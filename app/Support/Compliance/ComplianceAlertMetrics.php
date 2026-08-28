<?php

declare(strict_types=1);

namespace App\Support\Compliance;

use App\Enums\SiteAtpReadinessStatus;
use App\Filament\App\Pages\AtpPartnerReadiness;
use App\Filament\App\Pages\ExpiryWorklist;
use App\Filament\App\Pages\IntegrationHealth;
use App\Filament\App\Pages\OrganizationSettings;
use App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource;
use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Filament\App\Resources\InboundConnections\InboundConnectionResource;
use App\Filament\App\Resources\OutboundConnections\OutboundConnectionResource;
use App\Filament\App\Resources\Sites\SiteResource;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Models\Site;
use App\Models\User;
use App\Support\Auth\SiteAccess;
use App\Support\Integrations\IntegrationHealthMetrics;
use App\Support\MasterData\AtpLicenseRelevance;
use App\Support\MasterData\SiteAtpReadiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Aggregates compliance and integration signals for the alert center.
 */
final class ComplianceAlertMetrics
{
    public function __construct(
        private readonly IntegrationHealthMetrics $integrationHealth,
        private readonly ExpiryAlertMetrics $expiryAlertMetrics,
    ) {}

    /**
     * @return list<array{severity: string, title: string, detail: string, href?: string}>
     */
    public function alerts(?User $user = null): array
    {
        $alerts = [];

        $alerts = array_merge($alerts, $this->integrationAlerts($user));
        $alerts = array_merge($alerts, $this->exceptionAlerts($user));
        $alerts = array_merge($alerts, $this->atpAlerts());
        $alerts = array_merge($alerts, $this->inboundQueueAlerts($user));
        $alerts = array_merge($alerts, $this->expiryAlerts());

        usort($alerts, fn (array $a, array $b): int => strcmp($a['severity'], $b['severity']));

        return $alerts;
    }

    /**
     * @return list<array{severity: string, title: string, detail: string, href?: string}>
     */
    private function integrationAlerts(?User $user): array
    {
        $alerts = [];
        $inbound = $this->integrationHealth->inboundStatusCountsLast24h($user);
        $outbound = $this->integrationHealth->outboundTransmissionCountsLast24h($user);
        $healthHref = $this->pageUrl(IntegrationHealth::class);

        if (($inbound['error'] ?? 0) > 0) {
            $alerts[] = $this->alert(
                'critical',
                'Inbound EPCIS errors (24h)',
                (string) ($inbound['error'] ?? 0).' inbound document(s) failed in the last 24 hours.',
                $healthHref ?? $this->resourceUrl(EpcisDocumentResource::class),
            );
        }

        if (($outbound['failed'] ?? 0) > 0) {
            $alerts[] = $this->alert(
                'critical',
                'Outbound transmit failures (24h)',
                (string) ($outbound['failed'] ?? 0).' outbound transmission(s) failed in the last 24 hours.',
                $healthHref ?? $this->resourceUrl(OutboundConnectionResource::class),
            );
        }

        $staleInbound = InboundConnection::query()
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('last_received_at')
                    ->orWhere('last_received_at', '<', now()->subDays(7));
            })
            ->count();

        if ($staleInbound > 0) {
            $alerts[] = $this->alert(
                'warning',
                'Stale inbound connections',
                "{$staleInbound} active inbound connection(s) have no success in 7+ days.",
                $this->resourceUrl(InboundConnectionResource::class) ?? $healthHref,
            );
        }

        $staleOutbound = OutboundConnection::query()
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('last_sent_at')
                    ->orWhere('last_sent_at', '<', now()->subDays(7));
            })
            ->count();

        if ($staleOutbound > 0) {
            $alerts[] = $this->alert(
                'warning',
                'Stale outbound connections',
                "{$staleOutbound} active outbound connection(s) have not transmitted in 7+ days.",
                $this->resourceUrl(OutboundConnectionResource::class) ?? $healthHref,
            );
        }

        return $alerts;
    }

    /**
     * @return list<array{severity: string, title: string, detail: string, href?: string}>
     */
    private function exceptionAlerts(?User $user): array
    {
        $query = ExceptionCase::query()->open();
        SiteAccess::constrainExceptionCases($query, $user);

        $open = (clone $query)->count();
        $aging = (clone $query)->where('created_at', '<', now()->subDays(3))->count();
        $href = $this->resourceUrl(ExceptionResource::class);

        $alerts = [];

        if ($open >= 10) {
            $alerts[] = $this->alert(
                'warning',
                'Exception backlog',
                "{$open} open exception case(s).",
                $href,
            );
        }

        if ($aging > 0) {
            $alerts[] = $this->alert(
                'critical',
                'Aging exceptions',
                "{$aging} case(s) open more than 3 days.",
                $href,
            );
        }

        return $alerts;
    }

    /**
     * @return list<array{severity: string, title: string, detail: string, href?: string}>
     */
    private function atpAlerts(): array
    {
        $keys = AtpLicenseRelevance::evaluationJurisdictionKeys();
        $orgHref = $this->pageUrl(OrganizationSettings::class);
        $atpHref = $this->pageUrl(AtpPartnerReadiness::class) ?? $this->resourceUrl(SiteResource::class);

        if ($keys === []) {
            return [$this->alert(
                'warning',
                'Organization jurisdictions not set',
                'Add facility sites with country/state, or set a preferred receiving state in Organization settings.',
                $orgHref,
            )];
        }

        $label = AtpLicenseRelevance::evaluationJurisdictionsLabel();

        $partnerSites = fn (): \Illuminate\Database\Eloquent\Builder => Site::query()
            ->where('is_active', true)
            ->whereHas('tradingPartner');

        $expired = SiteAtpReadiness::applyStatusFilter(
            $partnerSites(),
            SiteAtpReadinessStatus::Expired,
        )->count();
        $expiring = SiteAtpReadiness::applyStatusFilter(
            $partnerSites(),
            SiteAtpReadinessStatus::Expiring,
        )->count();
        $missing = SiteAtpReadiness::applyStatusFilter(
            $partnerSites(),
            SiteAtpReadinessStatus::NoLicenses,
        )->count();

        $alerts = [];

        if ($expired > 0) {
            $alerts[] = $this->alert(
                'critical',
                'ATP licenses expired',
                "{$expired} partner site(s) have expired ATP/WDD evidence for {$label}.",
                $atpHref,
            );
        }

        if ($expiring > 0) {
            $alerts[] = $this->alert(
                'warning',
                'ATP licenses expiring',
                "{$expiring} partner site(s) have ATP/WDD expiring within 90 days for {$label}.",
                $atpHref,
            );
        }

        if ($missing > 0) {
            $alerts[] = $this->alert(
                'critical',
                'Missing ATP evidence',
                "{$missing} partner site(s) lack in-force licence on record for {$label}.",
                $atpHref,
            );
        }

        return $alerts;
    }

    /**
     * @return list<array{severity: string, title: string, detail: string, href?: string}>
     */
    private function inboundQueueAlerts(?User $user): array
    {
        $query = EpcisDocument::query()
            ->inboundCatalog()
            ->whereIn('status', ['received', 'parsing'])
            ->where('received_at', '<', now()->subHours(6));

        if ($user !== null) {
            SiteAccess::constrainInboundDocuments($query, $user);
        }

        $stale = $query->count();

        if ($stale === 0) {
            return [];
        }

        return [$this->alert(
            'warning',
            'Stale inbound queue',
            "{$stale} inbound document(s) still parsing after 6+ hours.",
            $this->resourceUrl(EpcisDocumentResource::class),
        )];
    }

    /**
     * @return list<array{severity: string, title: string, detail: string, href?: string}>
     */
    private function expiryAlerts(): array
    {
        $counts = $this->expiryAlertMetrics->counts();
        $href = $this->pageUrl(ExpiryWorklist::class);
        $alerts = [];

        if (($counts['expired'] ?? 0) > 0) {
            $alerts[] = $this->alert(
                'critical',
                'Expired on-hand serials',
                (string) $counts['expired'].' on-hand SGTIN(s) are past ILMD expiry.',
                $href,
            );
        }

        if (($counts['soon_30'] ?? 0) > 0) {
            $alerts[] = $this->alert(
                'warning',
                'Expiry within 30 days',
                (string) $counts['soon_30'].' on-hand SGTIN(s) expire within 30 days.',
                $href,
            );
        } elseif (($counts['soon_90'] ?? 0) > 0) {
            $alerts[] = $this->alert(
                'warning',
                'Expiry within 90 days',
                (string) $counts['soon_90'].' on-hand SGTIN(s) expire within 90 days.',
                $href,
            );
        }

        return $alerts;
    }

    /**
     * @return Collection<int, array{partner: string, site: string, status: string, detail: string}>
     */
    public function partnerAtpRows(): Collection
    {
        return Site::query()
            ->where('is_active', true)
            ->whereHas('tradingPartner', fn (Builder $partners): Builder => $partners->where('is_active', true))
            ->with('tradingPartner:id,name')
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(function (Site $site): array {
                $summary = SiteAtpReadiness::summarize($site);

                return [
                    'partner' => (string) ($site->tradingPartner?->name ?? '—'),
                    'site' => (string) ($site->name ?? $site->gln ?? 'Site #'.$site->getKey()),
                    'status' => $summary['status']->value ?? (string) $summary['status'],
                    'detail' => SiteAtpReadiness::badgeDescription($site) ?? SiteAtpReadiness::badgeLabel($site),
                ];
            });
    }

    /**
     * @return array{severity: string, title: string, detail: string, href?: string}
     */
    private function alert(string $severity, string $title, string $detail, ?string $href): array
    {
        $alert = [
            'severity' => $severity,
            'title' => $title,
            'detail' => $detail,
        ];

        if (filled($href)) {
            $alert['href'] = $href;
        }

        return $alert;
    }

    /**
     * @param  class-string  $page
     */
    private function pageUrl(string $page): ?string
    {
        try {
            if (! $page::canAccess()) {
                return null;
            }

            return $page::getUrl(panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  class-string  $resource
     */
    private function resourceUrl(string $resource): ?string
    {
        try {
            if (! $resource::canAccess()) {
                return null;
            }

            return $resource::getUrl('index', panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }
}
