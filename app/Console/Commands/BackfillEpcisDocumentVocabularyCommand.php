<?php

namespace App\Console\Commands;

use App\Actions\Epcis\BackfillEpcisDocumentVocabulary;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use Illuminate\Console\Command;

class BackfillEpcisDocumentVocabularyCommand extends Command
{
    protected $signature = 'tracepharma:epcis-backfill-vocabulary
        {--tenant= : Tenant id (required unless already in tenancy)}
        {--document= : Limit to a single epcis_documents.id}
        {--force : Re-upsert even when vocabulary rows already exist}
        {--limit=500 : Max documents to process}';

    protected $description = 'Header-only backfill of EPCISMasterData vocabulary into document vocab tables';

    public function handle(BackfillEpcisDocumentVocabulary $backfill): int
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
                ->orderBy('id');

            if ($this->option('document') !== null) {
                $query->whereKey((int) $this->option('document'));
            }

            $limit = max(1, (int) $this->option('limit'));
            $force = (bool) $this->option('force');

            $processed = 0;
            $persistedClasses = 0;
            $persistedLocations = 0;
            $skipped = 0;

            foreach ($query->cursor() as $document) {
                if ($processed >= $limit) {
                    break;
                }

                /** @var EpcisDocument $document */
                $result = $backfill->handle($document, $force);
                $processed++;

                if ($result['skipped']) {
                    $skipped++;
                    $this->line("skip #{$document->id}: {$result['reason']}");

                    continue;
                }

                $persistedClasses += $result['product_classes'];
                $persistedLocations += $result['locations'];
                $this->info(
                    "ok #{$document->id}: classes={$result['product_classes']} locations={$result['locations']}",
                );
            }

            $this->newLine();
            $this->info("Tenant: {$tenant->id}");
            $this->info("Documents processed: {$processed}");
            $this->info("Product classes upserted: {$persistedClasses}");
            $this->info("Locations upserted: {$persistedLocations}");
            $this->info("Skipped: {$skipped}");

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
            return Tenant::query()->find((string) $tenantId);
        }

        if (tenancy()->initialized && tenant() instanceof Tenant) {
            return tenant();
        }

        return null;
    }
}
