<?php

namespace App\Console\Commands;

use App\Actions\Receiving\BackfillInboundShipments;
use App\Models\Tenant;
use Illuminate\Console\Command;

class BackfillInboundShipmentsCommand extends Command
{
    protected $signature = 'tracepharma:backfill-inbound-shipments
        {--tenant= : Tenant id (required unless already in tenancy)}
        {--document= : Limit to a single epcis_documents.id}
        {--limit=500 : Max documents to process}';

    protected $description = 'Attach inbound EPCIS documents with ASN to inbound_shipments';

    public function handle(BackfillInboundShipments $backfill): int
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
            $documentId = $this->option('document') !== null
                ? (int) $this->option('document')
                : null;
            $limit = max(1, (int) $this->option('limit'));

            $result = $backfill->handle($documentId, $limit);

            $this->info("Tenant: {$tenant->id}");
            $this->info("Documents processed: {$result['processed']}");
            $this->info("Attached: {$result['attached']}");
            $this->info("Skipped: {$result['skipped']}");

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
