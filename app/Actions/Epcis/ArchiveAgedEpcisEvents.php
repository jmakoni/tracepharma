<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\EpcisEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * MOVE aged epcis_events into archive tables. Never hard-delete without an archive copy.
 * Copies event children (parties, locations, biz transactions, quantities, ILMD) so CASCADE
 * cannot destroy TI/TS catalog after the hot row is removed.
 *
 * Second pass deletes hot orphans: aged rows that already exist in archive (e.g. MOVE copied
 * then failed before hot delete) once child completeness matches MOVE checks.
 *
 * Does not delete or rewrite epcis_documents.payload_* files. Outbound TI pedigree rebuild
 * ({@see \App\Support\Epcis\ExtractPriorPedigreeXml}) prefers DB pedigree XML fragments, then
 * falls back to retained payloads for {@see config('tracepharma.epcis.payload_retention_years')}.
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
     * @return array{would_archive: int, archived: int, would_delete_orphans: int, deleted_orphans: int}
     */
    public function handle(bool $dryRun = false): array
    {
        if (! Schema::hasTable('epcis_events_archive') || ! Schema::hasTable('event_epcs_archive')) {
            return [
                'would_archive' => 0,
                'archived' => 0,
                'would_delete_orphans' => 0,
                'deleted_orphans' => 0,
            ];
        }

        $years = max(1, (int) config('tracepharma.epcis.retention_years', 6));
        $cutoff = now()->subYears($years);

        $eligible = EpcisEvent::query()
            ->where('event_time', '<', $cutoff)
            ->whereNotIn('id', function ($query): void {
                $query->select('id')->from('epcis_events_archive');
            });

        $orphans = $this->orphanQuery($cutoff);

        $wouldArchive = (int) $eligible->count();
        $wouldDeleteOrphans = (int) $orphans->count();

        if ($dryRun) {
            Log::info('EPCIS event archive dry-run.', [
                'would_archive' => $wouldArchive,
                'archived' => 0,
                'would_delete_orphans' => $wouldDeleteOrphans,
                'deleted_orphans' => 0,
            ]);

            return [
                'would_archive' => $wouldArchive,
                'archived' => 0,
                'would_delete_orphans' => $wouldDeleteOrphans,
                'deleted_orphans' => 0,
            ];
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

                $this->archiveAggregationLinks($ids);

                EpcisEvent::query()->whereIn('id', $ids)->delete();
                $archived += $copied;
            });
        });

        $deletedOrphans = 0;

        $orphans->orderBy('id')->chunkById(self::CHUNK, function ($events) use (&$deletedOrphans): void {
            $ids = $events->map(fn (EpcisEvent $event): int => (int) $event->getKey())->all();
            if ($ids === []) {
                return;
            }

            DB::transaction(function () use ($ids, &$deletedOrphans): void {
                $this->assertArchiveChildrenComplete($ids);
                $this->archiveAggregationLinks($ids);

                foreach (self::CHILD_TABLES as $pair) {
                    if (Schema::hasTable($pair['hot'])) {
                        DB::table($pair['hot'])->whereIn('event_id', $ids)->delete();
                    }
                }

                $deleted = EpcisEvent::query()->whereIn('id', $ids)->delete();
                $deletedOrphans += (int) $deleted;
            });
        });

        Log::info('EPCIS event archive complete.', [
            'would_archive' => $wouldArchive,
            'archived' => $archived,
            'would_delete_orphans' => $wouldDeleteOrphans,
            'deleted_orphans' => $deletedOrphans,
        ]);

        return [
            'would_archive' => $wouldArchive,
            'archived' => $archived,
            'would_delete_orphans' => $wouldDeleteOrphans,
            'deleted_orphans' => $deletedOrphans,
        ];
    }

    /**
     * Aged hot events that already have an archive row (failed MOVE left dual stores).
     *
     * @return Builder<EpcisEvent>
     */
    private function orphanQuery(\DateTimeInterface $cutoff): Builder
    {
        return EpcisEvent::query()
            ->where('event_time', '<', $cutoff)
            ->whereIn('id', function ($query): void {
                $query->select('id')->from('epcis_events_archive');
            });
    }

    /**
     * Refuse hot orphan delete unless archive already has a complete child copy (MOVE parity).
     *
     * @param  list<int>  $ids
     */
    private function assertArchiveChildrenComplete(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        foreach (self::CHILD_TABLES as $pair) {
            if (! Schema::hasTable($pair['hot'])) {
                continue;
            }

            $hotCount = (int) DB::table($pair['hot'])->whereIn('event_id', $ids)->count();
            if ($hotCount === 0) {
                continue;
            }

            if (! Schema::hasTable($pair['archive'])) {
                throw new RuntimeException(
                    "Archive table [{$pair['archive']}] is missing; refusing to delete {$hotCount} hot [{$pair['hot']}] row(s).",
                );
            }

            $archiveCount = (int) DB::table($pair['archive'])->whereIn('event_id', $ids)->count();
            if ($archiveCount !== $hotCount) {
                throw new RuntimeException(
                    "Archive copy incomplete for [{$pair['hot']}] → [{$pair['archive']}]: hot={$hotCount} archive={$archiveCount}.",
                );
            }
        }
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

    /**
     * Aggregation links are hierarchy SoR (not CASCADE children). Copy provenance
     * for links established by aged events, then nullify hot established_by_event_id
     * so deleting the hot event leaves no dangling FK while open/closed windows remain.
     *
     * @param  list<int>  $ids
     */
    private function archiveAggregationLinks(array $ids): void
    {
        if ($ids === []
            || ! Schema::hasTable('aggregation_links')
            || ! Schema::hasColumn('aggregation_links', 'established_by_event_id')
        ) {
            return;
        }

        $hotCount = (int) DB::table('aggregation_links')
            ->whereIn('established_by_event_id', $ids)
            ->count();

        if ($hotCount === 0) {
            return;
        }

        if (! Schema::hasTable('aggregation_links_archive')) {
            throw new RuntimeException(
                "Archive table [aggregation_links_archive] is missing; refusing to archive {$hotCount} aggregation_links row(s).",
            );
        }

        $columns = array_values(array_intersect(
            Schema::getColumnListing('aggregation_links'),
            Schema::getColumnListing('aggregation_links_archive'),
        ));

        if ($columns === []) {
            throw new RuntimeException(
                'No overlapping columns for aggregation_links → aggregation_links_archive; refusing to archive links.',
            );
        }

        $quoted = implode(', ', array_map(fn (string $column): string => '`'.$column.'`', $columns));
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        DB::insert(
            "INSERT IGNORE INTO aggregation_links_archive ({$quoted}) SELECT {$quoted} FROM aggregation_links WHERE established_by_event_id IN ({$placeholders})",
            $ids,
        );

        $archiveCount = (int) DB::table('aggregation_links_archive')
            ->whereIn('established_by_event_id', $ids)
            ->count();

        if ($archiveCount !== $hotCount) {
            throw new RuntimeException(
                "Archive copy incomplete for aggregation_links: hot={$hotCount} archive={$archiveCount}.",
            );
        }

        DB::table('aggregation_links')
            ->whereIn('established_by_event_id', $ids)
            ->update(['established_by_event_id' => null]);
    }
}
