<?php

namespace App\Console\Commands;

use App\Actions\Epcis\SyncDocumentEpcsFromEvents;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncAuthoredDocumentEpcsCommand extends Command
{
    protected $signature = 'tracepharma:epcis-sync-document-epcs
        {--tenant= : Tenant id; defaults to the current tenancy context}
        {--document= : Optional document id to sync}
        {--dry-run : Report documents that would be synced without syncing}';

    protected $description = 'Backfill document_epcs from event_epcs for authored documents missing the projection';

    public function handle(SyncDocumentEpcsFromEvents $sync): int
    {
        $tenant = $this->resolveTenant();
        if ($tenant === null) {
            return self::FAILURE;
        }

        $alreadyOnTenant = tenancy()->initialized
            && tenant() instanceof Tenant
            && tenant()->getKey() === $tenant->getKey();

        if (! $alreadyOnTenant) {
            tenancy()->initialize($tenant);
        }

        try {
            if (! Schema::hasTable('document_epcs')) {
                $this->warn('document_epcs table is not present; nothing to sync.');

                return self::SUCCESS;
            }

            $query = EpcisDocument::query()->orderBy('id');
            if ($this->option('document') !== null) {
                $query->whereKey((int) $this->option('document'));
            }

            $dryRun = (bool) $this->option('dry-run');
            $candidates = 0;
            $synced = 0;
            $epcs = 0;

            $query->each(function (EpcisDocument $document) use ($sync, $dryRun, &$candidates, &$synced, &$epcs): void {
                $generation = (int) ($document->ingest_generation ?? 1);
                if ($generation < 1) {
                    $generation = 1;
                }

                $documentEpcCount = (int) DB::table('document_epcs')
                    ->where('document_id', $document->getKey())
                    ->when(
                        Schema::hasColumn('document_epcs', 'ingest_generation'),
                        fn ($q) => $q->where('ingest_generation', $generation),
                    )
                    ->count();

                if ($documentEpcCount > 0) {
                    return;
                }

                $eventEpcQuery = DB::table('event_epcs')
                    ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
                    ->where('epcis_events.document_id', $document->getKey());

                if (Schema::hasColumn('epcis_events', 'ingest_generation')) {
                    $eventEpcQuery->where('epcis_events.ingest_generation', $generation);
                }

                $eventEpcCount = (int) $eventEpcQuery
                    ->distinct()
                    ->count('event_epcs.epc_id');

                if ($eventEpcCount === 0) {
                    return;
                }

                $candidates++;

                if ($dryRun) {
                    $this->line(
                        "document #{$document->getKey()} gen={$generation} event_epcs={$eventEpcCount} document_epcs={$documentEpcCount}",
                    );

                    return;
                }

                $count = $sync->handle($document);
                $synced++;
                $epcs += $count;
                $this->line("document #{$document->getKey()} synced epc_count={$count}");
            });

            $this->info("Tenant: {$tenant->id} ({$tenant->name})");
            if ($dryRun) {
                $this->info("Would sync documents={$candidates}");
            } else {
                $this->info("Synced documents={$synced} epcs={$epcs}");
            }

            return self::SUCCESS;
        } finally {
            if (! $alreadyOnTenant) {
                tenancy()->end();
            }
        }
    }

    private function resolveTenant(): ?Tenant
    {
        $tenantId = $this->option('tenant');

        if (filled($tenantId)) {
            $tenant = Tenant::query()->find($tenantId);
            if ($tenant === null) {
                $this->error("Tenant [{$tenantId}] was not found.");
            }

            return $tenant;
        }

        if (tenancy()->initialized && tenant() instanceof Tenant) {
            return tenant();
        }

        $this->error('Pass --tenant= or run inside an initialized tenancy context.');

        return null;
    }
}
