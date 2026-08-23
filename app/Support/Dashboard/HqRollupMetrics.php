<?php

namespace App\Support\Dashboard;

use App\Models\Exceptions\ExceptionCase;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Verification;
use App\Support\Auth\SiteAccess;

/**
 * Multi-site HQ rollup. Analytics / Dashboard stay unchanged.
 */
final class HqRollupMetrics
{
    /**
     * @return list<array{
     *     site_id: int,
     *     name: string,
     *     receive_expected: int,
     *     receive_confirmed: int,
     *     receive_pct: ?float,
     *     exceptions_open: int,
     *     aging_7d_plus: int,
     *     vrs_total: int,
     *     vrs_blocked: int,
     *     vrs_fail_pct: ?float
     * }>
     */
    public function bySite(): array
    {
        $sites = Site::query()
            ->ownedByOrganization()
            ->where('is_active', true)
            ->whereNotNull('gln')
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($sites->isEmpty()) {
            return [];
        }

        $receive = $this->receiveFill();
        $exceptions = $this->exceptionAging();
        $vrs = $this->vrsFail();

        $rows = [];
        foreach ($sites as $site) {
            $id = (int) $site->getKey();
            $expected = $receive[$id]['expected'] ?? 0;
            $confirmed = $receive[$id]['confirmed'] ?? 0;
            $vrsTotal = $vrs[$id]['total'] ?? 0;
            $vrsBlocked = $vrs[$id]['blocked'] ?? 0;

            $rows[] = [
                'site_id' => $id,
                'name' => (string) $site->name,
                'receive_expected' => $expected,
                'receive_confirmed' => $confirmed,
                'receive_pct' => $expected > 0 ? round(($confirmed / $expected) * 100, 1) : null,
                'exceptions_open' => $exceptions[$id]['open'] ?? 0,
                'aging_7d_plus' => $exceptions[$id]['old'] ?? 0,
                'vrs_total' => $vrsTotal,
                'vrs_blocked' => $vrsBlocked,
                'vrs_fail_pct' => $vrsTotal > 0 ? round(($vrsBlocked / $vrsTotal) * 100, 1) : null,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array{expected: int, confirmed: int}>
     */
    private function receiveFill(): array
    {
        $rows = ReceivingSession::query()
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->subDays(30))
            ->whereNotNull('site_id')
            ->toBase()
            ->selectRaw('site_id, COALESCE(SUM(expected_child_count), 0) as expected, COALESCE(SUM(confirmed_child_count), 0) as confirmed')
            ->groupBy('site_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->site_id] = [
                'expected' => (int) $row->expected,
                'confirmed' => (int) $row->confirmed,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{open: int, old: int}>
     */
    private function exceptionAging(): array
    {
        $query = ExceptionCase::query()->open()->whereNotNull('site_id');
        SiteAccess::constrainExceptionCases($query);

        $cutoff = now()->subDays(7);
        $rows = (clone $query)
            ->toBase()
            ->selectRaw('site_id, COUNT(*) as open_count, SUM(CASE WHEN created_at < ? THEN 1 ELSE 0 END) as old_count', [$cutoff])
            ->groupBy('site_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->site_id] = [
                'open' => (int) $row->open_count,
                'old' => (int) $row->old_count,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{total: int, blocked: int}>
     */
    private function vrsFail(): array
    {
        $query = Verification::query()
            ->where('verifications.created_at', '>=', now()->subDays(30));

        SiteAccess::constrainVerifications($query, 'exception', 'verified_by');

        $rows = $query
            ->leftJoin('exceptions', 'exceptions.id', '=', 'verifications.exception_id')
            ->toBase()
            ->selectRaw('COALESCE(exceptions.site_id, CAST(JSON_UNQUOTE(JSON_EXTRACT(verifications.request_payload, \'$.site_id\')) AS UNSIGNED)) as site_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN verifications.status IN ('failed', 'suspect') THEN 1 ELSE 0 END) as blocked")
            ->groupByRaw('COALESCE(exceptions.site_id, CAST(JSON_UNQUOTE(JSON_EXTRACT(verifications.request_payload, \'$.site_id\')) AS UNSIGNED))')
            ->havingRaw('site_id IS NOT NULL AND site_id > 0')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->site_id] = [
                'total' => (int) $row->total,
                'blocked' => (int) $row->blocked,
            ];
        }

        return $out;
    }
}
