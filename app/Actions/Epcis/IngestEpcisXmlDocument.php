<?php

namespace App\Actions\Epcis;

use App\Enums\EpcisReceivedVia;
use App\Models\Epcis\EpcisDocument;

/**
 * Backward-compatible path-based EPCIS ingest (receive + process).
 */
final class IngestEpcisXmlDocument
{
    public function __construct(
        private readonly ReceiveEpcisUpload $receive,
        private readonly ProcessEpcisDocument $process,
    ) {}

    /**
     * @param  array{
     *     direction?: string,
     *     received_via?: string|EpcisReceivedVia|null,
     *     original_filename?: string|null,
     *     payload_disk?: string,
     *     trading_partner_id?: int|null,
     *     notes?: string|null
     * }  $options
     */
    public function handle(string $absolutePath, array $options = []): EpcisDocument
    {
        $document = $this->receive->handle($absolutePath, [
            'direction' => $options['direction'] ?? 'inbound',
            'received_via' => $options['received_via'] ?? EpcisReceivedVia::Cli,
            'original_filename' => $options['original_filename'] ?? basename($absolutePath),
            'disk' => $options['payload_disk'] ?? config('tracepharma.epcis.payload_disk', 'local'),
            'trading_partner_id' => $options['trading_partner_id'] ?? null,
            'notes' => $options['notes'] ?? null,
            'dispatch' => false,
            'sync' => false,
        ]);

        return $this->process->handle($document);
    }

    /**
     * Parse and persist into an existing document (payload already stored).
     */
    public function ingestIntoDocument(EpcisDocument $document, ?string $absolutePath = null): EpcisDocument
    {
        return $this->process->handle($document);
    }
}
