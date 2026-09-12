<?php

declare(strict_types=1);

namespace App\Support\BuyingGroup;

use App\Models\BuyingGroupMember;
use App\Models\BuyingGroupMemberMetric;
use App\Models\BuyingGroupPartnerFact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Snapshot readers for BG Filament pages (no live member fan-out).
 */
final class BuyingGroupNetworkSnapshots
{
    /**
     * @return Collection<int, array{
     *     member_name: string,
     *     member_tenant_id: ?string,
     *     soft_only: bool,
     *     as_of: ?string,
     *     atp_gap_count: int|string,
     *     exceptions_open: int|string,
     *     exceptions_aging_7d: int|string,
     *     connection_unhealthy: bool|string,
     *     last_epcis_success_at: ?string,
     *     health_score: float|string|null,
     *     risk_score: float|string|null,
     *     live_metrics: bool
     * }>
     */
    public function memberHealthRows(?Carbon $asOf = null): Collection
    {
        $asOfDate = ($asOf ?? $this->latestMetricsAsOf() ?? now())->toDateString();

        $metrics = BuyingGroupMemberMetric::query()
            ->whereDate('as_of', $asOfDate)
            ->get()
            ->keyBy('member_tenant_id');

        return BuyingGroupMember::query()
            ->orderBy('name')
            ->get()
            ->map(function (BuyingGroupMember $member) use ($metrics, $asOfDate): array {
                $tenantId = filled($member->member_tenant_id)
                    ? (string) $member->member_tenant_id
                    : null;

                if ($tenantId === null) {
                    return $this->softOnlyRow($member);
                }

                $metric = $metrics->get($tenantId);

                if ($metric === null) {
                    return [
                        'member_name' => (string) $member->name,
                        'member_tenant_id' => $tenantId,
                        'soft_only' => false,
                        'as_of' => $asOfDate,
                        'atp_gap_count' => 'N/A',
                        'exceptions_open' => 'N/A',
                        'exceptions_aging_7d' => 'N/A',
                        'connection_unhealthy' => 'N/A',
                        'last_epcis_success_at' => null,
                        'health_score' => 'N/A',
                        'risk_score' => 'N/A',
                        'live_metrics' => false,
                        'empty_hint' => 'Awaiting first rollup',
                    ];
                }

                return [
                    'member_name' => (string) $member->name,
                    'member_tenant_id' => $tenantId,
                    'soft_only' => false,
                    'as_of' => $metric->as_of?->toDateString(),
                    'atp_gap_count' => (int) $metric->atp_gap_count,
                    'exceptions_open' => (int) $metric->exceptions_open,
                    'exceptions_aging_7d' => (int) $metric->exceptions_aging_7d,
                    'connection_unhealthy' => (bool) $metric->connection_unhealthy,
                    'last_epcis_success_at' => $metric->last_epcis_success_at?->toDateTimeString(),
                    'health_score' => $metric->health_score,
                    'risk_score' => $metric->risk_score,
                    'live_metrics' => true,
                    'empty_hint' => null,
                ];
            })
            ->sortByDesc(function (array $row): int {
                if (! $row['live_metrics']) {
                    return -1;
                }

                return (int) ($row['exceptions_aging_7d'] ?? 0)
                    + (int) ($row['atp_gap_count'] ?? 0) * 2
                    + ((bool) ($row['connection_unhealthy'] ?? false) ? 10 : 0);
            })
            ->values();
    }

    /**
     * @return Collection<int, array{
     *     member_name: string,
     *     member_tenant_id: ?string,
     *     soft_only: bool,
     *     partner_name: string,
     *     partner_gln: ?string,
     *     license_status: string,
     *     expires_at: ?string,
     *     as_of: ?string
     * }>
     */
    public function partnerMatrixRows(?Carbon $asOf = null, ?string $statusFilter = null): Collection
    {
        $asOfDate = ($asOf ?? $this->latestPartnerFactsAsOf() ?? now())->toDateString();

        $roster = BuyingGroupMember::query()
            ->orderBy('name')
            ->get()
            ->keyBy(fn (BuyingGroupMember $m): string => (string) ($m->member_tenant_id ?? 'soft:'.$m->getKey()));

        $rows = collect();

        foreach ($roster as $member) {
            if (! filled($member->member_tenant_id)) {
                $rows->push([
                    'member_name' => (string) $member->name,
                    'member_tenant_id' => null,
                    'soft_only' => true,
                    'partner_name' => '—',
                    'partner_gln' => null,
                    'license_status' => 'N/A',
                    'expires_at' => null,
                    'as_of' => null,
                    'empty_hint' => 'Link tenant for live metrics',
                ]);

                continue;
            }

            $facts = BuyingGroupPartnerFact::query()
                ->where('member_tenant_id', $member->member_tenant_id)
                ->whereDate('as_of', $asOfDate)
                ->when(
                    filled($statusFilter),
                    fn ($q) => $q->where('license_status', $statusFilter),
                )
                ->orderBy('partner_name')
                ->limit(500)
                ->get();

            if ($facts->isEmpty()) {
                $rows->push([
                    'member_name' => (string) $member->name,
                    'member_tenant_id' => (string) $member->member_tenant_id,
                    'soft_only' => false,
                    'partner_name' => '—',
                    'partner_gln' => null,
                    'license_status' => 'N/A',
                    'expires_at' => null,
                    'as_of' => $asOfDate,
                    'empty_hint' => 'Awaiting first rollup',
                ]);

                continue;
            }

            foreach ($facts as $fact) {
                $rows->push([
                    'member_name' => (string) $member->name,
                    'member_tenant_id' => (string) $member->member_tenant_id,
                    'soft_only' => false,
                    'partner_name' => (string) $fact->partner_name,
                    'partner_gln' => $fact->partner_gln,
                    'license_status' => (string) $fact->license_status,
                    'expires_at' => $fact->expires_at?->toDateString(),
                    'as_of' => $fact->as_of?->toDateString(),
                    'empty_hint' => null,
                ]);
            }
        }

        return $rows->values();
    }

    public function latestMetricsAsOf(): ?Carbon
    {
        $value = BuyingGroupMemberMetric::query()->max('as_of');

        return filled($value) ? Carbon::parse((string) $value) : null;
    }

    public function latestPartnerFactsAsOf(): ?Carbon
    {
        $value = BuyingGroupPartnerFact::query()->max('as_of');

        return filled($value) ? Carbon::parse((string) $value) : null;
    }

    /**
     * @return array{
     *     member_name: string,
     *     member_tenant_id: ?string,
     *     soft_only: bool,
     *     as_of: ?string,
     *     atp_gap_count: string,
     *     exceptions_open: string,
     *     exceptions_aging_7d: string,
     *     connection_unhealthy: string,
     *     last_epcis_success_at: null,
     *     health_score: string,
     *     risk_score: string,
     *     live_metrics: bool,
     *     empty_hint: string
     * }
     */
    private function softOnlyRow(BuyingGroupMember $member): array
    {
        return [
            'member_name' => (string) $member->name,
            'member_tenant_id' => null,
            'soft_only' => true,
            'as_of' => null,
            'atp_gap_count' => 'N/A',
            'exceptions_open' => 'N/A',
            'exceptions_aging_7d' => 'N/A',
            'connection_unhealthy' => 'N/A',
            'last_epcis_success_at' => null,
            'health_score' => 'N/A',
            'risk_score' => 'N/A',
            'live_metrics' => false,
            'empty_hint' => 'Link tenant for live metrics',
        ];
    }
}
