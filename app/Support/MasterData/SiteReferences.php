<?php

namespace App\Support\MasterData;

use App\Models\Site;
use DomainException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Reference audit for tenant sites, the site-level twin of {@see TradingPartnerReferences}.
 *
 * Site foreign keys on traceability tables are nullable + nullOnDelete, so a hard delete
 * silently detaches the ship-from/ship-to location of an EPCIS document, the site a
 * receiving or shipping session happened at, or the location a label batch was
 * commissioned from. DSCSA requires those records to keep naming the physical location
 * for the full retention window, so a referenced site must be deactivated, not deleted.
 */
final class SiteReferences
{
    /**
     * Countable references that must block a hard delete, keyed by table.
     *
     * Excluded on purpose: site-owned children removed with the site — `atp_licenses`
     * (deleted by the model's deleting hook), `read_points`, `location_devices` and
     * `site_user` (cascaded by the database), `devices` (reassignable equipment) — and
     * unissued `sscc_number_ranges`, which the deleting hook cleans up.
     *
     * @var array<string, array{columns: list<string>, label: string}>
     */
    private const BLOCKING = [
        'epcis_documents' => ['columns' => ['ship_from_site_id', 'ship_to_site_id'], 'label' => 'EPCIS document'],
        'event_parties' => ['columns' => ['site_id'], 'label' => 'EPCIS event party'],
        'event_locations' => ['columns' => ['site_id'], 'label' => 'EPCIS event location'],
        'epcis_unmatched_glns' => ['columns' => ['site_id'], 'label' => 'resolved unmatched GLN'],
        'epcis_jobs' => ['columns' => ['ship_from_site_id'], 'label' => 'EPCIS job'],
        'receiving_sessions' => ['columns' => ['site_id'], 'label' => 'receiving session'],
        'outbound_shipping_sessions' => ['columns' => ['site_id', 'ship_to_site_id'], 'label' => 'outbound shipping session'],
        'transferring_sessions' => ['columns' => ['from_site_id', 'to_site_id'], 'label' => 'transfer session'],
        'sscc_label_batches' => ['columns' => ['commission_site_id'], 'label' => 'SSCC label batch'],
    ];

    private const ISSUED_RANGES = 'sscc_number_ranges';

    private const ISSUED_RANGES_LABEL = 'issued SSCC number range';

    /**
     * Counting stops here so sites on high-volume tables stay cheap to audit.
     */
    private const CAP = 50;

    /**
     * Schema probe results per tenant database, so the hot path stays at one query.
     * Tenants whose migrations lag simply have fewer places to check.
     *
     * @var array<string, array{tables: array<string, list<string>>, issued_ranges: bool}>
     */
    private static array $schemaProbe = [];

    /**
     * Answered in a single query because the delete policy runs this for every row of the
     * sites table.
     */
    public static function isReferenced(Site $site): bool
    {
        $siteId = $site->getKey();

        if ($siteId === null) {
            return false;
        }

        $selects = [];
        $bindings = [];

        foreach (self::availableTables() as $table => $columns) {
            $conditions = implode(' or ', array_map(
                static fn (string $column): string => "`{$column}` = ?",
                $columns,
            ));

            $selects[] = "exists (select 1 from `{$table}` where ({$conditions}))";

            foreach ($columns as $ignored) {
                $bindings[] = $siteId;
            }
        }

        if (self::hasIssuedRangesTable()) {
            $selects[] = 'exists (select 1 from `'.self::ISSUED_RANGES.'` where `site_id` = ? and `current_number` > `start_number`)';
            $bindings[] = $siteId;
        }

        if ($selects === []) {
            return false;
        }

        $row = DB::selectOne('select '.implode(' or ', $selects).' as is_referenced', $bindings);

        return (bool) ($row->is_referenced ?? false);
    }

    /**
     * Reference counts keyed by singular label, capped per table.
     *
     * @return array<string, int>
     */
    public static function counts(Site $site): array
    {
        $siteId = $site->getKey();

        if ($siteId === null) {
            return [];
        }

        $counts = [];

        foreach (self::availableTables() as $table => $columns) {
            $count = self::countRows($table, $columns, (int) $siteId);

            if ($count > 0) {
                $counts[self::BLOCKING[$table]['label']] = $count;
            }
        }

        if (self::hasIssuedRangesTable()) {
            $issuedRanges = self::countRows(
                self::ISSUED_RANGES,
                ['site_id'],
                (int) $siteId,
                fn (QueryBuilder $query) => $query->whereColumn('current_number', '>', 'start_number'),
            );

            if ($issuedRanges > 0) {
                $counts[self::ISSUED_RANGES_LABEL] = $issuedRanges;
            }
        }

        return $counts;
    }

    /**
     * Human summary of what is holding the site, e.g. "4 EPCIS documents, 1 receiving session".
     */
    public static function summary(Site $site): ?string
    {
        $counts = self::counts($site);

        if ($counts === []) {
            return null;
        }

        $parts = [];

        foreach ($counts as $label => $count) {
            $parts[] = $count > self::CAP
                ? self::CAP.'+ '.Str::plural($label)
                : $count.' '.Str::plural($label, $count);
        }

        return implode(', ', $parts);
    }

    /**
     * @throws DomainException when traceability records still name the site
     */
    public static function assertDeletable(Site $site): void
    {
        $summary = self::summary($site);

        if ($summary === null) {
            return;
        }

        throw new DomainException(
            self::label($site).' is referenced by '.$summary.'. Deactivate it instead of deleting it.',
        );
    }

    /**
     * Names the site so the message still reads when the delete cascades from a partner.
     */
    private static function label(Site $site): string
    {
        $name = trim((string) $site->name);

        return $name === '' ? 'This site' : 'Site '.$name;
    }

    /**
     * @param  list<string>  $columns
     */
    private static function countRows(string $table, array $columns, int $siteId, ?callable $constrain = null): int
    {
        $query = DB::table($table)
            ->where(function (QueryBuilder $inner) use ($columns, $siteId): void {
                foreach ($columns as $column) {
                    $inner->orWhere($column, $siteId);
                }
            })
            ->select(DB::raw('1'));

        if ($constrain !== null) {
            $constrain($query);
        }

        return $query->limit(self::CAP + 1)->get()->count();
    }

    /**
     * @return array<string, list<string>>
     */
    private static function availableTables(): array
    {
        return self::schemaProbe()['tables'];
    }

    private static function hasIssuedRangesTable(): bool
    {
        return self::schemaProbe()['issued_ranges'];
    }

    /**
     * @return array{tables: array<string, list<string>>, issued_ranges: bool}
     */
    private static function schemaProbe(): array
    {
        $database = (string) DB::connection()->getDatabaseName();

        if (isset(self::$schemaProbe[$database])) {
            return self::$schemaProbe[$database];
        }

        $tables = [];

        foreach (self::BLOCKING as $table => $definition) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = array_values(array_filter(
                $definition['columns'],
                static fn (string $column): bool => Schema::hasColumn($table, $column),
            ));

            if ($columns !== []) {
                $tables[$table] = $columns;
            }
        }

        return self::$schemaProbe[$database] = [
            'tables' => $tables,
            'issued_ranges' => Schema::hasTable(self::ISSUED_RANGES)
                && Schema::hasColumn(self::ISSUED_RANGES, 'site_id'),
        ];
    }
}
