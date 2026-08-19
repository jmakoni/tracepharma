<?php

namespace App\Support\MasterData;

use App\Models\TradingPartner;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Reference audit for tenant trading partners.
 *
 * Every `trading_partner_id` foreign key is nullable + nullOnDelete, so a hard delete
 * silently detaches traceability records (EPCIS documents/events, receiving and shipping
 * sessions, exceptions, FDA 3911 reports, connections) instead of failing. DSCSA requires
 * those records to keep naming their counterparty for the full retention window, so a
 * referenced partner must be deactivated rather than deleted.
 */
final class TradingPartnerReferences
{
    /**
     * Countable references that must block a hard delete, keyed by table.
     *
     * Excluded on purpose: `sites` and `trading_partner_product` (partner-owned children
     * that are removed with the partner) and unissued `sscc_number_ranges` (cleaned up by
     * the model's deleting hook). Partner sites are cascade-deleted by that same hook and
     * are audited in turn by {@see SiteReferences}, so a site the traceability tables
     * still name blocks the partner delete too.
     *
     * @var array<string, string>
     */
    private const BLOCKING = [
        'epcis_documents' => 'EPCIS document',
        'epcis_events' => 'EPCIS event',
        'event_parties' => 'EPCIS event party',
        'transmission_mdns' => 'EPCIS transmission receipt',
        'epcis_unmatched_glns' => 'resolved unmatched GLN',
        'receiving_sessions' => 'receiving session',
        'outbound_shipping_sessions' => 'outbound shipping session',
        'exceptions' => 'exception case',
        'fda_3911_reports' => 'FDA 3911 report',
        'inbound_connections' => 'inbound connection',
        'inbound_connection_trading_partner' => 'inbound connection route',
        'outbound_connections' => 'outbound connection',
        'sscc_label_batches' => 'SSCC label batch',
        'products' => 'labeled product',
    ];

    private const ISSUED_RANGES = 'sscc_number_ranges';

    private const ISSUED_RANGES_LABEL = 'issued SSCC number range';

    /**
     * Counting stops here so partners on high-volume tables stay cheap to audit.
     */
    private const CAP = 50;

    /**
     * Schema probe results per tenant database, so the hot path stays at one query.
     * Tenants whose migrations lag simply have fewer places to check.
     *
     * @var array<string, array{tables: list<string>, issued_ranges: bool}>
     */
    private static array $schemaProbe = [];

    /**
     * Answered in a single query because the delete policy runs this for every row of the
     * partner table.
     */
    public static function isReferenced(TradingPartner $partner): bool
    {
        $partnerId = $partner->getKey();

        if ($partnerId === null) {
            return false;
        }

        $selects = [];
        $bindings = [];

        foreach (self::availableTables() as $table) {
            $selects[] = "exists (select 1 from `{$table}` where `trading_partner_id` = ?)";
            $bindings[] = $partnerId;
        }

        if (self::hasIssuedRangesTable()) {
            $selects[] = 'exists (select 1 from `'.self::ISSUED_RANGES.'` where `trading_partner_id` = ? and `current_number` > `start_number`)';
            $bindings[] = $partnerId;
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
    public static function counts(TradingPartner $partner): array
    {
        $partnerId = $partner->getKey();

        if ($partnerId === null) {
            return [];
        }

        $counts = [];

        foreach (self::availableTables() as $table) {
            $count = self::countRows($table, (int) $partnerId);

            if ($count > 0) {
                $counts[self::BLOCKING[$table]] = $count;
            }
        }

        if (self::hasIssuedRangesTable()) {
            $issuedRanges = self::countRows(
                self::ISSUED_RANGES,
                (int) $partnerId,
                fn ($query) => $query->whereColumn('current_number', '>', 'start_number'),
            );

            if ($issuedRanges > 0) {
                $counts[self::ISSUED_RANGES_LABEL] = $issuedRanges;
            }
        }

        return $counts;
    }

    /**
     * Human summary of what is holding the partner, e.g. "4 EPCIS documents, 1 receiving session".
     */
    public static function summary(TradingPartner $partner): ?string
    {
        $counts = self::counts($partner);

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
     * @throws DomainException when traceability records still name the partner
     */
    public static function assertDeletable(TradingPartner $partner): void
    {
        $summary = self::summary($partner);

        if ($summary === null) {
            return;
        }

        throw new DomainException(
            'This trading partner is referenced by '.$summary.'. Deactivate it instead of deleting it.',
        );
    }

    private static function countRows(string $table, int $partnerId, ?callable $constrain = null): int
    {
        $query = DB::table($table)
            ->where('trading_partner_id', $partnerId)
            ->select(DB::raw('1'));

        if ($constrain !== null) {
            $constrain($query);
        }

        return $query->limit(self::CAP + 1)->get()->count();
    }

    /**
     * @return list<string>
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
     * @return array{tables: list<string>, issued_ranges: bool}
     */
    private static function schemaProbe(): array
    {
        $database = (string) DB::connection()->getDatabaseName();

        return self::$schemaProbe[$database] ??= [
            'tables' => array_values(array_filter(
                array_keys(self::BLOCKING),
                static fn (string $table): bool => Schema::hasTable($table)
                    && Schema::hasColumn($table, 'trading_partner_id'),
            )),
            'issued_ranges' => Schema::hasTable(self::ISSUED_RANGES),
        ];
    }
}
