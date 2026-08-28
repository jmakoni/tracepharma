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
 *
 * Quiet connections (no receive/send for N days) are not alerts — low volume is normal.
 * Integration digests go to Support Engineers; compliance digests to compliance contacts.
 */
final class ComplianceAlertMetrics
{
    public const AUDIENCE_COMPLIANCE = 'compliance';

    public const AUDIENCE_INTEGRATION = 'integration';

    public function __construct(
        private readonly IntegrationHealthMetrics $integrationHealth,
        private readonly ExpiryAlertMetrics $expiryAlertMetrics,
    ) {}

    /**
     * @return list<array{severity: string, title: string, detail: string, audience: string, href?: string}>
     */
    public function alerts(?User $user = null): array
    {
        $alerts = array_merge(
            $this->integrationAlerts($user),
            $this->exceptionAlerts($user),
            $this->atpAlerts(),
            $this->inboundQueueAlerts($user),
            $this->expiryAlerts(),
        );

        usort($alerts, fn (array $a, array $b): int => strcmp($a['severity'], $b['severity']));

        return $alerts;
    }

    /**
     * @return list<array{severity: string, title: string, detail: string, audience: string, href?: string}>
     */
    public function alertsForAudience(string $audience, ?User $user = null): array
    {
        return array_values(array_filter(
            $this->alerts($user),
            fn (array $alert): bool => ($alert['audience'] ?? self::AUDIENCE_COMPLIANCE) === $audience,
        ));
    }

    /**
     * @return list<array{severity: string, title: string, detail: string, audience: string, href?: string}>
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
                self::AUDIENCE_INTEGRATION,
                $healthHref ?? $this->resourceUrl(EpcisDocumentResource::class),
            );
        }

        if (($outbound['failed'] ?? 0) > 0) {
            $alerts[] = $this->alert(
                'critical',
                'Outbound transmit failures (24h)',
                (string) ($outbound['failed'] ?? 0).' outbound transmission(s) failed in the last 24 hours.',
                self::AUDIENCE_INTEGRATION,
                $healthHref ?? $this->resourceUrl(OutboundConnectionResource::class),
            );
        }

        $inboundErrors = InboundConnection::query()
            ->where('is_active', true)
            ->whereNotNull('last_error')
            ->where('last_error', '!=', '')
            ->orderBy('id')
            ->pluck('name')
            ->filter()
            ->values()
            ->all();

        if ($inboundErrors !== []) {
            $alerts[] = $this->alert(
                'critical',
                'Inbound connection errors',
                count($inboundErrors).' active inbound connection(s) report an error: '
                    .implode('; ', array_slice($inboundErrors, 0, 5))
                    .(count($inboundErrors) > 5 ? '; and '.(count($inboundErrors) - 5).' more' : '')
                    .'.',
                self::AUDIENCE_INTEGRATION,
                $this->resourceUrl(InboundConnectionResource::class) ?? $healthHref,
            );
        }

        $outboundErrors = OutboundConnection::query()
            ->where('is_active', true)
            ->whereNotNull('last_error')
            ->where('last_error', '!=', '')
            ->orderBy('id')
            ->pluck('name')
            ->filter()
            ->values()
            ->all();

        if ($outboundErrors !== []) {
            $alerts[] = $this->alert(
                'critical',
                'Outbound connection errors',
                count($outboundErrors).' active outbound connection(s) report an error: '
                    .implode('; ', array_slice($outboundErrors, 0, 5))
                    .(count($outboundErrors) > 5 ? '; and '.(count($outboundErrors) - 5).' more' : '')
                    .'.',
                self::AUDIENCE_INTEGRATION,
                $this->resourceUrl(OutboundConnectionResource::class) ?? $healthHref,
            );
        }

        return $alerts;
    }

    /**
     * @return list<array{severity: string, title: string, detail: string, audience: string, href?: string}>
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
                self::AUDIENCE_COMPLIANCE,
                $href,
            );
        }

        if ($aging > 0) {
            $alerts[] = $this->alert(
                'critical',
                'Aging exceptions',
                "{$aging} case(s) open more than 3 days.",
                self::AUDIENCE_COMPLIANCE,
                $href,
            );
        }

        return $alerts;
    }

    /**
     * @return list<array{severity: string, title: string, detail: string, audience: string, href?: string}>
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
                self::AUDIENCE_COMPLIANCE,
                $orgHref,
            )];
        }

        $label = AtpLicenseRelevance::evaluationJurisdictionsLabel();

        $sites = Site::query()
            ->where('is_active', true)
            ->whereHas('tradingPartner')
            ->with('tradingPartner:id,name,partner_type,fda_organization_id')
            ->orderBy('id')
            ->get()
            ->filter(fn (Site $site): bool => AtpLicenseRelevance::siteInComplianceAlertScope($site));

        $expired = [];
        $expiring = [];
        $missing = [];

        foreach ($sites as $site) {
            $status = SiteAtpReadiness::summarize($site)['status'];
            $siteLabel = $this->atpSiteLabel($site);

            match ($status) {
                SiteAtpReadinessStatus::Expired => $expired[] = $siteLabel,
                SiteAtpReadinessStatus::Expiring => $expiring[] = $siteLabel,
                SiteAtpReadinessStatus::NoLicenses => $missing[] = $siteLabel,
                default => null,
            };
        }

        sort($expired);
        sort($expiring);
        sort($missing);

        $alerts = [];

        if ($expired !== []) {
            $alerts[] = $this->alert(
                'critical',
                'ATP licenses expired',
                $this->formatAtpSiteDetail($expired, $label, 'have expired ATP/WDD evidence'),
                self::AUDIENCE_COMPLIANCE,
                $atpHref,
            );
        }

        if ($expiring !== []) {
            $alerts[] = $this->alert(
                'warning',
                'ATP licenses expiring',
                $this->formatAtpSiteDetail($expiring, $label, 'have ATP/WDD expiring within 90 days'),
                self::AUDIENCE_COMPLIANCE,
                $atpHref,
            );
        }

        if ($missing !== []) {
            $alerts[] = $this->alert(
                'critical',
                'Missing ATP evidence',
                $this->formatAtpSiteDetail($missing, $label, 'lack in-force licence'),
                self::AUDIENCE_COMPLIANCE,
                $atpHref,
            );
        }

        return $alerts;
    }

    /**
     * @param  list<string>  $siteLabels
     */
    private function formatAtpSiteDetail(array $siteLabels, string $jurisdictionLabel, string $verb): string
    {
        $count = count($siteLabels);

        if ($count === 0) {
            return '';
        }

        $shown = array_slice($siteLabels, 0, 5);
        $list = implode('; ', $shown);

        if ($count > 5) {
            $list .= '; and '.($count - 5).' more';
        }

        return "{$count} partner site(s) {$verb} for {$jurisdictionLabel}: {$list}.";
    }

    private function atpSiteLabel(Site $site): string
    {
        $partner = (string) ($site->tradingPartner?->name ?? 'Partner');
        $siteName = (string) ($site->name ?? $site->gln ?? 'Site #'.$site->getKey());

        return $partner.' — '.$siteName;
    }

    /**
     * @return list<array{severity: string, title: string, detail: string, audience: string, href?: string}>
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
            self::AUDIENCE_INTEGRATION,
            $this->resourceUrl(EpcisDocumentResource::class),
        )];
    }

    /**
     * @return list<array{severity: string, title: string, detail: string, audience: string, href?: string}>
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
                self::AUDIENCE_COMPLIANCE,
                $href,
            );
        }

        if (($counts['soon_30'] ?? 0) > 0) {
            $alerts[] = $this->alert(
                'warning',
                'Expiry within 30 days',
                (string) $counts['soon_30'].' on-hand SGTIN(s) expire within 30 days.',
                self::AUDIENCE_COMPLIANCE,
                $href,
            );
        } elseif (($counts['soon_90'] ?? 0) > 0) {
            $alerts[] = $this->alert(
                'warning',
                'Expiry within 90 days',
                (string) $counts['soon_90'].' on-hand SGTIN(s) expire within 90 days.',
                self::AUDIENCE_COMPLIANCE,
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
     * @return array{severity: string, title: string, detail: string, audience: string, href?: string}
     */
    private function alert(string $severity, string $title, string $detail, string $audience, ?string $href): array
    {
        $alert = [
            'severity' => $severity,
            'title' => $title,
            'detail' => $detail,
            'audience' => $audience,
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
