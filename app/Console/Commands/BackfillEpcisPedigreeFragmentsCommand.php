<?php

namespace App\Console\Commands;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisPedigreeEventFragment;
use App\Models\Tenant;
use App\Support\Epcis\PersistPedigreeXmlFragments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class BackfillEpcisPedigreeFragmentsCommand extends Command
{
    protected $signature = 'tracepharma:epcis-backfill-pedigree-fragments
        {--tenant= : Tenant id (required unless already in tenancy)}
        {--document= : Limit to a single epcis_documents.id}
        {--force : Re-extract even when fragments already exist for the active generation}
        {--limit=500 : Max documents to process}';

    protected $description = 'Backfill lossless commissioning/packing + vocab XML fragments for outbound TI when payloads are later missing';

    public function handle(PersistPedigreeXmlFragments $persist): int
    {
        $tenant = $this->resolveTenant();
        if ($tenant === null) {
            $this->error('Tenant required: pass --tenant= or run inside an initialized tenancy.');

            return self::FAILURE;
        }

        $alreadyOnTenant = tenancy()->initialized
            && tenant() instanceof Tenant
            && tenant()->getKey() === $tenant->getKey();

        if (! $alreadyOnTenant) {
            tenancy()->initialize($tenant);
        }

        try {
            if (! Schema::hasTable('epcis_pedigree_event_fragments')) {
                $this->error('Pedigree fragment tables missing — run tenant migrations first.');

                return self::FAILURE;
            }

            $query = EpcisDocument::query()
                ->whereNotNull('payload_path')
                ->where('status', '!=', 'error')
                ->orderBy('id');

            if ($this->option('document') !== null) {
                $query->whereKey((int) $this->option('document'));
            }

            $limit = max(1, (int) $this->option('limit'));
            $force = (bool) $this->option('force');

            $processed = 0;
            $events = 0;
            $vocab = 0;
            $skipped = 0;
            $failed = 0;

            foreach ($query->cursor() as $document) {
                if ($processed >= $limit) {
                    break;
                }

                /** @var EpcisDocument $document */
                $processed++;
                $generation = (int) ($document->ingest_generation ?? 1);

                if (! $force && EpcisPedigreeEventFragment::query()
                    ->where('document_id', $document->getKey())
                    ->where('ingest_generation', $generation)
                    ->exists()) {
                    $skipped++;
                    $this->line("skip #{$document->id}: fragments already present");

                    continue;
                }

                try {
                    $result = $persist->forDocument($document);
                    $events += $result['events'];
                    $vocab += $result['vocab'];
                    $this->info(
                        "ok #{$document->id}: events={$result['events']} vocab={$result['vocab']}",
                    );
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("fail #{$document->id}: {$e->getMessage()}");
                }
            }

            $this->newLine();
            $this->info("Tenant: {$tenant->id}");
            $this->info("Documents processed: {$processed}");
            $this->info("Event fragments written: {$events}");
            $this->info("Vocab fragments written: {$vocab}");
            $this->info("Skipped: {$skipped}");
            $this->info("Failed: {$failed}");

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
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
            return Tenant::query()->find((string) $tenantId);
        }

        if (tenancy()->initialized && tenant() instanceof Tenant) {
            return tenant();
        }

        return null;
    }
}
