<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use App\Enums\ExceptionSeverity;
use App\Enums\TracingRequestStatus;
use App\Models\AtpLicense;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Models\TracingRequest;
use App\Models\User;
use App\Models\Verification;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\MasterData\AtpLicenseExpiry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AnalyticsMetrics
{
    public function __construct(
        private readonly User $user,
        private readonly int $rangeDays = 30,
        private readonly ?int $siteId = null,
        private readonly ?int $tradingPartnerId = null,
    ) {}

    public static function make(
        User $user,
        int $rangeDays = 30,
        ?int $siteId = null,
        ?int $tradingPartnerId = null,
    ): self {
        return new self(
            $user,
            $rangeDays === 7 ? 7 : 30,
            $siteId,
            $tradingPartnerId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function forKey(string $key): array
    {
        return match ($key) {
            'volume_trends' => $this->volumeTrends(),
            'exception_aging' => $this->exceptionAging(),
            'tracing_sla_score' => $this->tracingSlaScore(),
            'vrs_rates' => $this->vrsRates(),
            'partner_throughput' => $this->partnerThroughput(),
            'integration_trends' => $this->integrationTrends(),
            'atp_expiry' => $this->atpExpiry(),
            'site_comparison' => $this->siteComparison(),
            default => ['key' => $key],
        };
    }

    /**
     * @return array{days: list<array{date: string, receive: int, ship: int}>, receive_total: int, ship_total: int}
     */
    public function volumeTrends(): array
    {
        $days = $this->emptyDayMap();
        $receiveByDay = $this->completedSessionCountsByDay(ReceivingSession::query());
        $shipByDay = $this->completedSessionCountsByDay(OutboundShippingSession::query());

        $rows = [];
        $receiveTotal = 0;
        $shipTotal = 0;

        foreach ($days as $date => $_) {
            $receive = $receiveByDay[$date] ?? 0;
            $ship = $shipByDay[$date] ?? 0;
            $receiveTotal += $receive;
            $shipTotal += $ship;
            $rows[] = [
                'date' => $date,
                'receive' => $receive,
                'ship' => $ship,
            ];
        }

        return [
            'days' => $rows,
            'receive_total' => $receiveTotal,
            'ship_total' => $shipTotal,
        ];
    }

    /**
     * @return array{
     *     bands: list<array{key: string, label: string, count: int}>,
     *     severities: list<array{key: string, label: string, count: int}>,
     *     total: int
     * }
     */
    public function exceptionAging(): array
    {
        $query = ExceptionCase::query()->open();
        SiteAccess::constrainExceptionCases($query, $this->user);
        $this->constrainSite($query, 'site_id');
        $this->constrainPartner($query);

        $oneDay = now()->subDay();
        $threeDays = now()->subDays(3);
        $sevenDays = now()->subDays(7);

        $rows = (clone $query)
            ->toBase()
            ->selectRaw(
                "CASE
                    WHEN created_at >= ? THEN '0-1d'
                    WHEN created_at >= ? THEN '1-3d'
                    WHEN created_at >= ? THEN '3-7d'
                    ELSE '7d+'
                END as age_band",
                [$oneDay, $threeDays, $sevenDays],
            )
            ->selectRaw('severity')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupByRaw('age_band, severity')
            ->get();

        $bandCounts = [
            '0-1d' => 0,
            '1-3d' => 0,
            '3-7d' => 0,
            '7d+' => 0,
        ];
        $severityCounts = [];
        foreach (ExceptionSeverity::cases() as $severity) {
            $severityCounts[$severity->value] = 0;
        }

        foreach ($rows as $row) {
            $band = (string) $row->age_band;
            $severity = (string) $row->severity;
            $count = (int) $row->aggregate;

            if (array_key_exists($band, $bandCounts)) {
                $bandCounts[$band] += $count;
            }

            if (array_key_exists($severity, $severityCounts)) {
                $severityCounts[$severity] += $count;
            }
        }

        $bandLabels = [
            '0-1d' => '0–1 day',
            '1-3d' => '1–3 days',
            '3-7d' => '3–7 days',
            '7d+' => '7+ days',
        ];

        return [
            'bands' => array_map(
                fn (string $key): array => [
                    'key' => $key,
                    'label' => $bandLabels[$key],
                    'count' => $bandCounts[$key],
                ],
                array_keys($bandCounts),
            ),
            'severities' => array_map(
                fn (ExceptionSeverity $severity): array => [
                    'key' => $severity->value,
                    'label' => $severity->label(),
                    'count' => $severityCounts[$severity->value],
                ],
                ExceptionSeverity::cases(),
            ),
            'total' => array_sum($bandCounts),
        ];
    }

    /**
     * @return array{
     *     on_time: int,
     *     late: int,
     *     pending: int,
     *     score_percent: float|null,
     *     at_risk: list<array{id: int, title: string, due_at: string|null, overdue: bool}>
     * }
     */
    public function tracingSlaScore(): array
    {
        $scored = $this->tracingBaseQuery()
            ->whereNotNull('due_at')
            ->where(function (Builder $query): void {
                $query->where('requested_at', '>=', $this->since())
                    ->orWhere(function (Builder $inner): void {
                        $inner->whereNull('requested_at')
                            ->where('created_at', '>=', $this->since());
                    });
            })
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN responded_at IS NOT NULL AND responded_at <= due_at THEN 1 ELSE 0 END) as on_time')
            ->selectRaw('SUM(CASE WHEN responded_at IS NOT NULL AND responded_at > due_at THEN 1 ELSE 0 END) as late')
            ->selectRaw('SUM(CASE WHEN responded_at IS NULL THEN 1 ELSE 0 END) as pending')
            ->first();

        $onTime = (int) ($scored?->on_time ?? 0);
        $late = (int) ($scored?->late ?? 0);
        $pending = (int) ($scored?->pending ?? 0);
        $responded = $onTime + $late;

        $atRisk = $this->tracingBaseQuery()
            ->whereIn('status', [
                TracingRequestStatus::Open->value,
                TracingRequestStatus::InProgress->value,
            ])
            ->whereNotNull('due_at')
            ->orderBy('due_at')
            ->limit(8)
            ->get(['id', 'title', 'due_at'])
            ->map(fn (TracingRequest $request): array => [
                'id' => (int) $request->getKey(),
                'title' => (string) $request->title,
                'due_at' => $request->due_at?->toDayDateTimeString(),
                'overdue' => $request->due_at !== null && $request->due_at->isPast(),
            ])
            ->all();

        return [
            'on_time' => $onTime,
            'late' => $late,
            'pending' => $pending,
            'score_percent' => $responded > 0 ? round(($onTime / $responded) * 100, 1) : null,
            'at_risk' => $atRisk,
        ];
    }

    /**
     * @return array{allowed: int, blocked: int, deferred: int, unavailable: int, total: int}
     */
    public function vrsRates(): array
    {
        $query = Verification::query()->where('created_at', '>=', $this->since());

        SiteAccess::constrainVerifications($query, 'exception', 'verified_by', $this->user);

        if ($this->siteId !== null || $this->tradingPartnerId !== null) {
            $query->where(function (Builder $outer): void {
                $outer->whereHas('exception', function (Builder $exception): void {
                    $this->constrainSite($exception, 'site_id');
                    $this->constrainPartner($exception);
                });

                if ($this->user->can(Permissions::SitesAccessAll)) {
                    $outer->orWhereNull('exception_id');
                } else {
                    $outer->orWhere(function (Builder $own): void {
                        $own->whereNull('exception_id')
                            ->where('verified_by', $this->user->getKey());
                    });
                }
            });
        }

        $counts = $query
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $allowed = $counts['verified'] ?? 0;
        $blocked = ($counts['failed'] ?? 0) + ($counts['suspect'] ?? 0);
        $deferred = $counts['deferred'] ?? 0;
        $unavailable = $counts['unavailable'] ?? 0;

        return [
            'allowed' => $allowed,
            'blocked' => $blocked,
            'deferred' => $deferred,
            'unavailable' => $unavailable,
            'total' => $allowed + $blocked + $deferred + $unavailable,
        ];
    }

    /**
     * @return array{partners: list<array{id: int, name: string, receive: int, ship: int, total: int}>}
     */
    public function partnerThroughput(): array
    {
        $receive = $this->completedSessionCountsByPartner(ReceivingSession::query());
        $ship = $this->completedSessionCountsByPartner(OutboundShippingSession::query());
        $ids = array_values(array_unique([...array_keys($receive), ...array_keys($ship)]));

        if ($ids === []) {
            return ['partners' => []];
        }

        $names = TradingPartner::query()
            ->whereIn('id', $ids)
            ->pluck('name', 'id');

        $partners = [];
        foreach ($ids as $id) {
            $receiveCount = $receive[$id] ?? 0;
            $shipCount = $ship[$id] ?? 0;
            $partners[] = [
                'id' => $id,
                'name' => (string) ($names[$id] ?? 'Partner #'.$id),
                'receive' => $receiveCount,
                'ship' => $shipCount,
                'total' => $receiveCount + $shipCount,
            ];
        }

        usort($partners, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return [
            'partners' => array_slice($partners, 0, 10),
        ];
    }

    /**
     * @return array{days: list<array{date: string, inbound_ok: int, inbound_wip: int, inbound_voided: int, inbound_fail: int, outbound_ok: int, outbound_fail: int}>}
     */
    public function integrationTrends(): array
    {
        $days = $this->emptyDayMap();

        $inbound = EpcisDocument::query()
            ->inboundCatalog()
            ->where('received_at', '>=', $this->since());
        $this->constrainDocumentSite($inbound, 'ship_to_site_id');
        $this->constrainPartner($inbound);

        $inboundRows = $inbound
            ->selectRaw('DATE(received_at) as day, status, COUNT(*) as aggregate')
            ->groupByRaw('DATE(received_at), status')
            ->get();

        $outbound = EpcisDocument::query()
            ->where('direction', 'outbound')
            ->where(function (Builder $query): void {
                $since = $this->since();
                $query->where('sent_at', '>=', $since)
                    ->orWhere(function (Builder $inner) use ($since): void {
                        $inner->whereNull('sent_at')
                            ->where('creation_date', '>=', $since);
                    });
            });
        $this->constrainDocumentSite($outbound, 'ship_from_site_id');
        $this->constrainPartner($outbound);

        $outboundRows = $outbound
            ->selectRaw("DATE(COALESCE(sent_at, creation_date)) as day")
            ->selectRaw("COALESCE(transmission_status, 'pending') as bucket")
            ->selectRaw('COUNT(*) as aggregate')
            ->groupByRaw("DATE(COALESCE(sent_at, creation_date)), bucket")
            ->get();

        $inboundOk = $days;
        $inboundWip = $days;
        $inboundVoided = $days;
        $inboundFail = $days;
        $outboundOk = $days;
        $outboundFail = $days;

        foreach ($inboundRows as $row) {
            $day = (string) $row->day;
            $count = (int) $row->aggregate;
            if (! array_key_exists($day, $days)) {
                continue;
            }

            $status = (string) $row->status;

            if (in_array($status, ['parsed', 'validated'], true)) {
                $inboundOk[$day] += $count;
            } elseif (in_array($status, ['received', 'parsing'], true)) {
                $inboundWip[$day] += $count;
            } elseif ($status === 'voided') {
                $inboundVoided[$day] += $count;
            } elseif ($status === 'error') {
                $inboundFail[$day] += $count;
            }
        }

        foreach ($outboundRows as $row) {
            $day = (string) $row->day;
            $count = (int) $row->aggregate;
            if (! array_key_exists($day, $days)) {
                continue;
            }

            if ((string) $row->bucket === 'sent') {
                $outboundOk[$day] += $count;
            } elseif ((string) $row->bucket === 'failed') {
                $outboundFail[$day] += $count;
            }
        }

        $rows = [];
        foreach ($days as $date => $_) {
            $rows[] = [
                'date' => $date,
                'inbound_ok' => $inboundOk[$date],
                'inbound_wip' => $inboundWip[$date],
                'inbound_voided' => $inboundVoided[$date],
                'inbound_fail' => $inboundFail[$date],
                'outbound_ok' => $outboundOk[$date],
                'outbound_fail' => $outboundFail[$date],
            ];
        }

        return ['days' => $rows];
    }

    /**
     * @return array{
     *     within_30: int,
     *     within_60: int,
     *     within_90: int,
     *     licenses: list<array{id: int, license_number: string, site_name: string|null, expires_on: string|null, days_left: int|null, site_id: int|null}>
     * }
     */
    public function atpExpiry(): array
    {
        $today = AtpLicenseExpiry::today();
        $day30 = $today->copy()->addDays(30);
        $day60 = $today->copy()->addDays(60);
        $day90 = $today->copy()->addDays(90);

        $query = AtpLicense::query()
            ->active()
            ->whereNotNull('license_expiration_date')
            ->whereDate('license_expiration_date', '>=', $today)
            ->whereDate('license_expiration_date', '<=', $day90);

        $this->constrainAtpSite($query);

        $aggregates = (clone $query)
            ->selectRaw(
                'SUM(CASE WHEN license_expiration_date <= ? THEN 1 ELSE 0 END) as within_30',
                [$day30->toDateString()],
            )
            ->selectRaw(
                'SUM(CASE WHEN license_expiration_date > ? AND license_expiration_date <= ? THEN 1 ELSE 0 END) as within_60',
                [$day30->toDateString(), $day60->toDateString()],
            )
            ->selectRaw(
                'SUM(CASE WHEN license_expiration_date > ? AND license_expiration_date <= ? THEN 1 ELSE 0 END) as within_90',
                [$day60->toDateString(), $day90->toDateString()],
            )
            ->first();

        $licenses = (clone $query)
            ->with('site:id,name')
            ->orderBy('license_expiration_date')
            ->limit(10)
            ->get(['id', 'site_id', 'license_number', 'license_state', 'license_expiration_date'])
            ->map(function (AtpLicense $license) use ($today): array {
                $expires = $license->license_expiration_date;

                return [
                    'id' => (int) $license->getKey(),
                    'license_number' => (string) $license->license_number,
                    'site_name' => $license->site?->name,
                    'expires_on' => $expires?->toDateString(),
                    'days_left' => $expires !== null ? (int) $today->diffInDays($expires, false) : null,
                    'site_id' => $license->site_id !== null ? (int) $license->site_id : null,
                ];
            })
            ->all();

        return [
            'within_30' => (int) ($aggregates?->within_30 ?? 0),
            'within_60' => (int) ($aggregates?->within_60 ?? 0),
            'within_90' => (int) ($aggregates?->within_90 ?? 0),
            'licenses' => $licenses,
        ];
    }

    /**
     * @return array{sites: list<array{id: int, name: string, receive: int, ship: int, total: int}>}
     */
    public function siteComparison(): array
    {
        $receive = $this->completedSessionCountsBySite(ReceivingSession::query());
        $ship = $this->completedSessionCountsBySite(OutboundShippingSession::query());
        $ids = array_values(array_unique([...array_keys($receive), ...array_keys($ship)]));

        if ($ids === []) {
            return ['sites' => []];
        }

        $names = Site::query()
            ->whereIn('id', $ids)
            ->pluck('name', 'id');

        $sites = [];
        foreach ($ids as $id) {
            $receiveCount = $receive[$id] ?? 0;
            $shipCount = $ship[$id] ?? 0;
            $sites[] = [
                'id' => $id,
                'name' => (string) ($names[$id] ?? 'Site #'.$id),
                'receive' => $receiveCount,
                'ship' => $shipCount,
                'total' => $receiveCount + $shipCount,
            ];
        }

        usort($sites, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return ['sites' => $sites];
    }

    public function since(): Carbon
    {
        return now()->subDays($this->rangeDays)->startOfDay();
    }

    /**
     * @return array<string, int>
     */
    private function emptyDayMap(): array
    {
        $days = [];
        $cursor = $this->since()->copy();
        $end = now()->startOfDay();

        while ($cursor->lte($end)) {
            $days[$cursor->toDateString()] = 0;
            $cursor->addDay();
        }

        return $days;
    }

    /**
     * @param  Builder<ReceivingSession>|Builder<OutboundShippingSession>  $query
     * @return array<string, int>
     */
    private function completedSessionCountsByDay(Builder $query): array
    {
        $this->constrainCompletedSessions($query);

        return $query
            ->selectRaw('DATE(completed_at) as day, COUNT(*) as aggregate')
            ->groupByRaw('DATE(completed_at)')
            ->pluck('aggregate', 'day')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @param  Builder<ReceivingSession>|Builder<OutboundShippingSession>  $query
     * @return array<int, int>
     */
    private function completedSessionCountsByPartner(Builder $query): array
    {
        $this->constrainCompletedSessions($query);
        $query->whereNotNull('trading_partner_id');

        return $query
            ->select('trading_partner_id', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('trading_partner_id')
            ->pluck('aggregate', 'trading_partner_id')
            ->mapWithKeys(fn (mixed $count, mixed $id): array => [(int) $id => (int) $count])
            ->all();
    }

    /**
     * @param  Builder<ReceivingSession>|Builder<OutboundShippingSession>  $query
     * @return array<int, int>
     */
    private function completedSessionCountsBySite(Builder $query): array
    {
        $this->constrainCompletedSessions($query);
        $query->whereNotNull('site_id');

        return $query
            ->select('site_id', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('site_id')
            ->pluck('aggregate', 'site_id')
            ->mapWithKeys(fn (mixed $count, mixed $id): array => [(int) $id => (int) $count])
            ->all();
    }

    /**
     * @param  Builder<ReceivingSession>|Builder<OutboundShippingSession>  $query
     */
    private function constrainCompletedSessions(Builder $query): void
    {
        $query->where('status', 'completed')
            ->where('completed_at', '>=', $this->since());

        $this->constrainSite($query, 'site_id');
        $this->constrainPartner($query);
    }

    /**
     * @return Builder<TracingRequest>
     */
    private function tracingBaseQuery(): Builder
    {
        $query = TracingRequest::query();
        SiteAccess::constrainExceptionCaseRelation($query, 'exceptionCase', $this->user);

        if ($this->siteId !== null || $this->tradingPartnerId !== null) {
            $query->whereHas('exceptionCase', function (Builder $exception): void {
                $this->constrainSite($exception, 'site_id');
                $this->constrainPartner($exception);
            });
        }

        return $query;
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function constrainSite(Builder $query, string $column): void
    {
        if ($this->siteId !== null) {
            $query->where($column, $this->siteId);

            return;
        }

        if ($this->user->can(Permissions::SitesAccessAll)) {
            return;
        }

        $query->whereIn($column, SiteAccess::userSiteIds($this->user));
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function constrainPartner(Builder $query, string $column = 'trading_partner_id'): void
    {
        if ($this->tradingPartnerId !== null) {
            $query->where($column, $this->tradingPartnerId);
        }
    }

    /**
     * @param  Builder<EpcisDocument>  $query
     */
    private function constrainDocumentSite(Builder $query, string $column): void
    {
        if ($this->siteId !== null) {
            $query->where($column, $this->siteId);

            return;
        }

        if ($this->user->can(Permissions::SitesAccessAll)) {
            return;
        }

        $query->whereIn($column, SiteAccess::userSiteIds($this->user));
    }

    /**
     * @param  Builder<AtpLicense>  $query
     */
    private function constrainAtpSite(Builder $query): void
    {
        if ($this->siteId !== null) {
            $query->where('site_id', $this->siteId);

            return;
        }

        if ($this->user->can(Permissions::SitesAccessAll)) {
            return;
        }

        $query->whereIn('site_id', SiteAccess::userSiteIds($this->user));
    }
}
