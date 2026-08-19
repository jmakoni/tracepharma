<?php

namespace App\Console\Commands;

use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Enums\EpcisReceivedVia;
use App\Models\Tenant;
use Illuminate\Console\Command;

class IngestEpcisXmlCommand extends Command
{
    protected $signature = 'tracepharma:ingest-epcis
        {path : Absolute path to EPCIS XML}
        {--tenant= : Tenant id (required)}
        {--direction=inbound}
        {--sync : Process inline instead of queueing}';

    protected $description = 'Ingest an EPCIS 1.2 XML document into a tenant database';

    public function handle(ReceiveEpcisUpload $receive): int
    {
        $tenantId = $this->option('tenant');
        if (! filled($tenantId)) {
            $this->error('The --tenant option is required.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');
        if (! str_starts_with($path, '/')) {
            $this->error('Path must be absolute.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->find($tenantId);
        if ($tenant === null) {
            $this->error("Tenant not found: {$tenantId}");

            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        try {
            $sync = (bool) $this->option('sync');
            $document = $receive->handle($path, [
                'direction' => (string) $this->option('direction'),
                'received_via' => EpcisReceivedVia::Cli,
                'original_filename' => basename($path),
                'dispatch' => ! $sync,
                'sync' => $sync,
            ]);

            $this->info('document_id='.$document->getKey());
            $this->info('document_uuid='.$document->document_uuid);
            $this->info('event_count='.$document->event_count);
            $this->info('epc_count='.$document->epc_count);
            $this->info('status='.$document->status);

            return self::SUCCESS;
        } finally {
            tenancy()->end();
        }
    }
}
