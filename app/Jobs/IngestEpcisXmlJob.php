<?php

namespace App\Jobs;

use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Enums\EpcisReceivedVia;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Adapter: receive an EPCIS file path, then process synchronously or via ProcessEpcisDocumentJob.
 */
class IngestEpcisXmlJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    /**
     * @param  array{
     *     direction?: string,
     *     received_via?: string|EpcisReceivedVia|null,
     *     original_filename?: string|null,
     *     payload_disk?: string,
     *     trading_partner_id?: int|null,
     *     inbound_connection_id?: int|null,
     *     notes?: string|null
     * }  $options
     */
    public function __construct(
        public Tenant $tenant,
        public string $absolutePath,
        public array $options = [],
    ) {}

    public function handle(): EpcisDocument
    {
        return $this->tenant->run(function (): EpcisDocument {
            $sync = Queue::getDefaultDriver() === 'sync';

            return app(ReceiveEpcisUpload::class)->handle($this->absolutePath, [
                'direction' => $this->options['direction'] ?? 'inbound',
                'received_via' => $this->options['received_via'] ?? EpcisReceivedVia::Cli,
                'original_filename' => $this->options['original_filename'] ?? basename($this->absolutePath),
                'disk' => $this->options['payload_disk'] ?? config('tracepharma.epcis.payload_disk', 'local'),
                'trading_partner_id' => $this->options['trading_partner_id'] ?? null,
                'inbound_connection_id' => $this->options['inbound_connection_id'] ?? null,
                'notes' => $this->options['notes'] ?? null,
                'dispatch' => true,
                'sync' => $sync,
            ]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('EPCIS XML ingest job failed', [
            'tenant_id' => $this->tenant->getKey(),
            'path' => $this->absolutePath,
            'original_filename' => $this->options['original_filename'] ?? null,
            'error' => $exception?->getMessage(),
        ]);
    }
}
