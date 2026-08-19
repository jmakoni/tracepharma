<?php

namespace App\Actions\Fda;

use App\Models\Fda\FdaOrganizationMatchReview;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Collapse duplicate pending FDA organization match reviews to the lowest id per key.
 */
final class DedupeFdaOrganizationMatchReviews
{
    /**
     * @return array{groups: int, deleted: int, kept: int, remaining_pending: int}
     */
    public function handle(?string $source = null, bool $dryRun = false): array
    {
        $connection = $this->connection();
        $table = (new FdaOrganizationMatchReview)->getTable();

        $sourceFilter = ($source !== null && $source !== '') ? $source : null;

        $groupsSql = "
            SELECT source, original_name, COALESCE(proposed_fda_organization_id, 0) AS proposed_org_key,
                   MIN(id) AS keep_id, COUNT(*) AS cnt
            FROM {$table}
            WHERE status = ?
        ";
        $bindings = [FdaOrganizationMatchReview::STATUS_PENDING];

        if ($sourceFilter !== null) {
            $groupsSql .= ' AND source = ?';
            $bindings[] = $sourceFilter;
        }

        $groupsSql .= '
            GROUP BY source, original_name, COALESCE(proposed_fda_organization_id, 0)
            HAVING COUNT(*) > 1
        ';

        $groups = $connection->select($groupsSql, $bindings);

        $groupCount = count($groups);
        $deleted = 0;
        $kept = 0;

        foreach ($groups as $group) {
            $surplus = (int) $group->cnt - 1;
            $deleted += $surplus;
            $kept++;
        }

        if (! $dryRun && $deleted > 0) {
            $deleteSql = "
                DELETE r FROM {$table} AS r
                INNER JOIN (
                    SELECT source, original_name, COALESCE(proposed_fda_organization_id, 0) AS proposed_org_key,
                           MIN(id) AS keep_id
                    FROM {$table}
                    WHERE status = ?
            ";
            $deleteBindings = [FdaOrganizationMatchReview::STATUS_PENDING];

            if ($sourceFilter !== null) {
                $deleteSql .= ' AND source = ?';
                $deleteBindings[] = $sourceFilter;
            }

            $deleteSql .= '
                    GROUP BY source, original_name, COALESCE(proposed_fda_organization_id, 0)
                    HAVING COUNT(*) > 1
                ) AS keepers
                    ON r.source = keepers.source
                    AND r.original_name = keepers.original_name
                    AND COALESCE(r.proposed_fda_organization_id, 0) = keepers.proposed_org_key
                    AND r.status = ?
                    AND r.id <> keepers.keep_id
            ';
            $deleteBindings[] = FdaOrganizationMatchReview::STATUS_PENDING;

            $connection->delete($deleteSql, $deleteBindings);
        }

        $remainingQuery = FdaOrganizationMatchReview::query()->pending();

        if ($sourceFilter !== null) {
            $remainingQuery->where('source', $sourceFilter);
        }

        return [
            'groups' => $groupCount,
            'deleted' => $deleted,
            'kept' => $kept,
            'remaining_pending' => $remainingQuery->count(),
        ];
    }

    private function connection(): Connection
    {
        return DB::connection((new FdaOrganizationMatchReview)->getConnectionName());
    }
}
