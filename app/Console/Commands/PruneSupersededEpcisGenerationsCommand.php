<?php

namespace App\Console\Commands;

use App\Actions\Epcis\PruneSupersededIngestGenerations;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneSupersededEpcisGenerationsCommand extends Command
{
    protected $signature = 'tracepharma:epcis-prune-superseded-generations
        {--tenant= : Tenant id; defaults to the current tenancy context}
        {--document= : Optional document id to prune}
        {--dry-run : Report rows that would be deleted without deleting}';

    protected $description = 'Delete superseded EPCIS ingest generations, keeping each document\'s active projection';

    public function handle(PruneSupersededIngestGenerations $prune): int
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
            if (
                ! Schema::hasColumn('epcis_documents', 'ingest_generation')
                || ! Schema::hasColumn('epcis_events', 'ingest_generation')
            ) {
                $this->warn('ingest_generation columns are not present; nothing to prune.');

                return self::SUCCESS;
            }

            $query = EpcisDocument::query()->orderBy('id');
            if ($this->option('document') !== null) {
                $query->whereKey((int) $this->option('document'));
            }

            $dryRun = (bool) $this->option('dry-run');
            $documents = 0;
            $events = 0;
            $documentEpcs = 0;

            $query->each(function (EpcisDocument $document) use ($prune, $dryRun, &$documents, &$events, &$documentEpcs): void {
                $keep = (int) ($document->ingest_generation ?? 1);
                $staleEvents = (int) EpcisEvent::query()
                    ->where('document_id', $document->getKey())
                    ->where('ingest_generation', '!=', $keep)
                    ->count();
                $staleDocumentEpcs = Schema::hasTable('document_epcs')
                    ? (int) DB::table('document_epcs')
                        ->where('document_id', $document->getKey())
                        ->where('ingest_generation', '!=', $keep)
                        ->count()
                    : 0;

                if ($staleEvents === 0 && $staleDocumentEpcs === 0) {
                    return;
                }

                $documents++;

                if ($dryRun) {
                    $orphanEvents = (int) EpcisEvent::query()
                        ->where('document_id', $document->getKey())
                        ->where('ingest_generation', '>', $keep)
                        ->count();
                    $orphanDocumentEpcs = Schema::hasTable('document_epcs')
                        ? (int) DB::table('document_epcs')
                            ->where('document_id', $document->getKey())
                            ->where('ingest_generation', '>', $keep)
                            ->count()
                        : 0;

                    $events += $staleEvents;
                    $documentEpcs += $staleDocumentEpcs;
                    $this->line(
                        "document #{$document->getKey()} keep_gen={$keep} events={$staleEvents} document_epcs={$staleDocumentEpcs}"
                        ." orphan_events={$orphanEvents} orphan_document_epcs={$orphanDocumentEpcs}",
                    );

                    return;
                }

                $stats = $prune->handle($document);
                $events += $stats['events_deleted'];
                $documentEpcs += $stats['document_epcs_deleted'];
                $this->line(
                    "document #{$document->getKey()} pruned events={$stats['events_deleted']} document_epcs={$stats['document_epcs_deleted']}",
                );
            });

            $this->info("Tenant: {$tenant->id} ({$tenant->name})");
            $this->info(($dryRun ? 'Would prune' : 'Pruned')." documents={$documents} events={$events} document_epcs={$documentEpcs}");

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
