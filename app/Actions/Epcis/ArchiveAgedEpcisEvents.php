<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\EpcisEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * MOVE aged epcis_events into archive tables. Never hard-delete without an archive copy.
 * Copies event children (parties, locations, biz transactions, quantities, ILMD) so CASCADE
 * cannot destroy TI/TS catalog after the hot row is removed.
 */
final class ArchiveAgedEpcisEvents
{
    public const CHUNK = 200;

    /**
     * @var list<array{hot: string, archive: string}>
     */
    private const CHILD_TABLES = [
        ['hot' => 'event_epcs', 'archive' => 'event_epcs_archive'],
        ['hot' => 'event_parties', 'archive' => 'event_parties_archive'],
        ['hot' => 'event_locations', 'archive' => 'event_locations_archive'],
        ['hot' => 'event_biz_transactions', 'archive' => 'event_biz_transactions_archive'],
        ['hot' => 'event_quantities', 'archive' => 'event_quantities_archive'],
        ['hot' => 'event_epc_ilmd', 'archive' => 'event_epc_ilmd_archive'],
    ];

    /**
     * @return array{would_archive: int, archived: int}
     */
    public function handle(bool $dryRun = false): array
    {
        if (! Schema::hasTable('epcis_events_archive') || ! Schema::hasTable('event_epcs_archive')) {
            return ['would_archive' => 0, 'archived' => 0];
        }

        $years = max(1, (int) config('tracepharma.epcis.retention_years', 6));
        $cutoff = now()->subYears($years);

        $eligible = EpcisEvent::query()
            ->where('event_time', '<', $cutoff)
            ->whereNotIn('id', function ($query): void {
                $query->select('id')->from('epcis_events_archive');
            });

        $wouldArchive = (int) $eligible->count();

        if ($dryRun) {
            Log::info('EPCIS event archive dry-run.', [
                'would_archive' => $wouldArchive,
                'archived' => 0,
            ]);

            return ['would_archive' => $wouldArchive, 'archived' => 0];
        }

        $archived = 0;

        $eligible->orderBy('id')->chunkById(self::CHUNK, function ($events) use (&$archived): void {
            $ids = $events->map(fn (EpcisEvent $event): int => (int) $event->getKey())->all();
            if ($ids === []) {
                return;
            }

            DB::transaction(function () use ($ids, &$archived): void {
                $this->copyEvents($ids);

                $copied = (int) DB::table('epcis_events_archive')->whereIn('id', $ids)->count();
                if ($copied !== count($ids)) {
                    throw new RuntimeException('Archive copy incomplete; hot events were not deleted.');
                }

                foreach (self::CHILD_TABLES as $pair) {
                    $this->copyChildRows($pair['hot'], $pair['archive'], $ids);
                }

                EpcisEvent::query()->whereIn('id', $ids)->delete();
                $archived += $copied;
            });
        });

        Log::info('EPCIS event archive complete.', [
            'would_archive' => $wouldArchive,
            'archived' => $archived,
        ]);

        return ['would_archive' => $wouldArchive, 'archived' => $archived];
    }

    /**
     * @param  list<int>  $ids
     */
    private function copyEvents(array $ids): void
    {
        $columns = array_values(array_intersect(
            Schema::getColumnListing('epcis_events'),
            array_diff(Schema::getColumnListing('epcis_events_archive'), ['archived_at']),
        ));

        if ($columns === [] || $ids === []) {
            return;
        }

        $quoted = implode(', ', array_map(fn (string $column): string => '`'.$column.'`', $columns));
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        DB::insert(
            "INSERT IGNORE INTO epcis_events_archive ({$quoted}, archived_at) SELECT {$quoted}, ? FROM epcis_events WHERE id IN ({$placeholders})",
            [now(), ...$ids],
        );
    }

    /**
     * @param  list<int>  $ids
     */
    private function copyChildRows(string $hotTable, string $archiveTable, array $ids): void
    {
        if ($ids === [] || ! Schema::hasTable($hotTable)) {
            return;
        }

        $hotCount = (int) DB::table($hotTable)->whereIn('event_id', $ids)->count();
        if ($hotCount === 0) {
            return;
        }

        if (! Schema::hasTable($archiveTable)) {
            throw new RuntimeException(
                "Archive table [{$archiveTable}] is missing; refusing to delete {$hotCount} hot [{$hotTable}] row(s).",
            );
        }

        $columns = array_values(array_intersect(
            Schema::getColumnListing($hotTable),
            Schema::getColumnListing($archiveTable),
        ));

        if ($columns === []) {
            throw new RuntimeException(
                "No overlapping columns for [{$hotTable}] → [{$archiveTable}]; refusing to delete hot children.",
            );
        }

        $quoted = implode(', ', array_map(fn (string $column): string => '`'.$column.'`', $columns));
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        DB::insert(
            "INSERT IGNORE INTO {$archiveTable} ({$quoted}) SELECT {$quoted} FROM {$hotTable} WHERE event_id IN ({$placeholders})",
            $ids,
        );

        $archiveCount = (int) DB::table($archiveTable)->whereIn('event_id', $ids)->count();
        if ($archiveCount !== $hotCount) {
            throw new RuntimeException(
                "Archive copy incomplete for [{$hotTable}] → [{$archiveTable}]: hot={$hotCount} archive={$archiveCount}.",
            );
        }
    }
}
