<?php

namespace App\Console\Commands;

use App\Actions\Epcis\ReprocessEpcisDocument;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reprocess existing EPCIS documents so event-level P1 fields
 * (event_epc_ilmd, extension_json, event_quantities, etc.) are written.
 */
class ReprocessEpcisDocumentsBackfillCommand extends Command
{
    protected $signature = 'tracepharma:epcis-reprocess-backfill
        {--tenant= : Tenant id (required unless already in tenancy)}
        {--document= : Limit to a single epcis_documents.id}
        {--sync : Run ProcessEpcisDocumentJob inline instead of queueing}
        {--force : Reprocess even when an open receiving session exists}
        {--limit=500 : Max documents to process}';

    protected $description = 'Reprocess EPCIS documents to backfill persisted event/vocabulary fidelity fields';

    public function handle(ReprocessEpcisDocument $reprocess): int
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
            $query = EpcisDocument::query()
                ->whereNotNull('payload_path')
                ->whereIn('status', ['parsed', 'validated', 'error', 'received'])
                ->orderBy('id');

            if ($this->option('document') !== null) {
                $query->whereKey((int) $this->option('document'));
            }

            $limit = max(1, (int) $this->option('limit'));
            $sync = (bool) $this->option('sync');
            $force = (bool) $this->option('force');

            $ok = 0;
            $failed = 0;
            $processed = 0;

            foreach ($query->cursor() as $document) {
                if ($processed >= $limit) {
                    break;
                }
                $processed++;

                /** @var EpcisDocument $document */
                try {
                    $path = $document->payloadAbsolutePath();
                    if ($path === null) {
                        $this->warn("skip #{$document->id}: payload unreadable");
                        $failed++;

                        continue;
                    }

                    $updated = $reprocess->handle(
                        $document,
                        sync: $sync,
                        force: $force,
                        authorizeExceptionsRole: false,
                    );
                    $ok++;
                    $this->info(
                        "ok #{$updated->id}: status={$updated->status} gen={$updated->ingest_generation} reprocess#={$updated->reprocess_count}",
                    );
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("fail #{$document->id}: ".$e->getMessage());
                }
            }

            $this->newLine();
            $this->info("Tenant: {$tenant->id}");
            $this->info("Processed: {$processed}");
            $this->info("Succeeded: {$ok}");
            $this->info('Failed/skipped: '.$failed);
            if (! $sync) {
                $this->comment('Jobs were queued — ensure a queue worker is running.');
            }

            return $failed > 0 && $ok === 0 ? self::FAILURE : self::SUCCESS;
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
