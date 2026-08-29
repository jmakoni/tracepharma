<?php

namespace App\Actions\Epcis;

use App\Actions\EpcisJobs\EnqueueInboundEpcisJob;
use App\Enums\EpcisReceivedVia;
use App\Exceptions\DuplicateEpcisUploadException;
use App\Jobs\ProcessEpcisDocumentJob;
use App\Models\Epcis\EpcisDocument;
use App\Services\Epcis\EpcisIngestionService;
use App\Support\Epcis\EpcisCacheLock;
use App\Support\Epcis\EpcisSchemaVersion;
use App\Support\Epcis\EpcisStoragePath;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Accept an EPCIS XML upload: store payload once, create received document, optionally dispatch processing.
 */
final class ReceiveEpcisUpload
{
    /**
     * @param  array{
     *     direction?: string,
     *     received_via?: string|EpcisReceivedVia|null,
     *     original_filename?: string|null,
     *     trading_partner_id?: int|null,
     *     inbound_connection_id?: int|null,
     *     outbound_connection_id?: int|null,
     *     notes?: string|null,
     *     disk?: string|null,
     *     dispatch?: bool,
     *     sync?: bool
     * }  $meta
     */
    public function handle(string $absolutePath, array $meta = []): EpcisDocument
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new \InvalidArgumentException("EPCIS file is missing or unreadable: {$absolutePath}");
        }

        $direction = (string) ($meta['direction'] ?? 'inbound');
        $disk = (string) ($meta['disk'] ?? config('tracepharma.epcis.payload_disk', 'local'));
        $originalFilename = $meta['original_filename'] ?? basename($absolutePath);

        $format = EpcisSchemaVersion::detectFormat($absolutePath);
        $extension = strtolower(pathinfo((string) $originalFilename, PATHINFO_EXTENSION));
        if (! in_array($extension, ['xml', 'json'], true)) {
            $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        }

        if ($format === EpcisSchemaVersion::FORMAT_JSON) {
            if (! EpcisSchemaVersion::accepts20()) {
                throw new \InvalidArgumentException(
                    EpcisSchemaVersion::accepts20PlatformOnly()
                        ? 'EPCIS 2.0 JSON-LD uploads are disabled for this tenant. Enable epcis.accept_20 in organization settings, or upload EPCIS 1.2/1.3 XML.'
                        : 'EPCIS 2.0 JSON-LD uploads are disabled. Set TRACEPHARMA_EPCIS_ACCEPT_20=true to enable, or upload EPCIS 1.2/1.3 XML.',
                );
            }
            if ($extension !== '' && $extension !== 'json') {
                throw new \InvalidArgumentException('EPCIS 2.0 upload must be a .json file.');
            }
            $extension = 'json';
        } elseif ($extension !== 'xml') {
            throw new \InvalidArgumentException('EPCIS upload must be an .xml file (or .json when EPCIS 2.0 is enabled).');
        }

        $schemaVersion = EpcisSchemaVersion::assertAccepted(
            EpcisSchemaVersion::peekFile($absolutePath),
            $format,
        );

        if ($schemaVersion === EpcisSchemaVersion::V20 && $format !== EpcisSchemaVersion::FORMAT_JSON) {
            if (! EpcisSchemaVersion::accepts20()) {
                throw new \InvalidArgumentException(
                    'EPCIS 2.0 XML uploads are disabled. Set TRACEPHARMA_EPCIS_ACCEPT_20=true to enable.',
                );
            }
            // XML EPCIS 2.0 is accepted when platform+tenant flags allow (Phase 2).
            if ($extension !== '' && $extension !== 'xml') {
                throw new \InvalidArgumentException('EPCIS 2.0 XML upload must be an .xml file.');
            }
            $extension = 'xml';
        }

        $tradingPartnerId = isset($meta['trading_partner_id']) ? (int) $meta['trading_partner_id'] : null;
        $inboundConnectionId = isset($meta['inbound_connection_id']) ? (int) $meta['inbound_connection_id'] : null;
        $outboundConnectionId = isset($meta['outbound_connection_id']) ? (int) $meta['outbound_connection_id'] : null;
        $notes = isset($meta['notes']) ? (string) $meta['notes'] : null;
        $receivedVia = $this->resolveReceivedVia($meta, $direction, $inboundConnectionId, $notes);
        $dispatch = (bool) ($meta['dispatch'] ?? true);
        $sync = (bool) ($meta['sync'] ?? false);

        $sha256 = hash_file('sha256', $absolutePath);
        if ($sha256 === false) {
            throw new \RuntimeException("Unable to hash EPCIS payload: {$absolutePath}");
        }

        // Serialize the duplicate check + insert per hash: without this lock, two
        // concurrent uploads of the same file can both pass the "no existing document"
        // check and each persist their own EpcisDocument row.
        // Named store (not Cache::__call): Stancl tags __call under tenancy.
        // Never the file store — php-fpm cannot write artisan-created SHA1 shards.
        $document = EpcisCacheLock::store()->lock($this->epcisUploadHashLockKey($direction, $sha256), 60)->block(10, function () use (
            $sha256,
            $disk,
            $direction,
            $tradingPartnerId,
            $inboundConnectionId,
            $outboundConnectionId,
            $receivedVia,
            $originalFilename,
            $notes,
            $absolutePath,
            $schemaVersion,
            $format,
            $extension,
        ): EpcisDocument {
            $existing = EpcisDocument::query()
                ->where('file_sha256', $sha256)
                ->where('direction', $direction)
                ->whereNotIn('status', ['error', 'voided'])
                ->first();

            if ($existing !== null) {
                throw new DuplicateEpcisUploadException($existing);
            }

            $payloadUuid = (string) Str::uuid();
            $payloadPath = EpcisStoragePath::onDisk($disk, "epcis/{$direction}/{$payloadUuid}.{$extension}");
            $this->storePayloadStream($disk, $payloadPath, $absolutePath, $format);

            return EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => $schemaVersion,
                'creation_date' => now(),
                'direction' => $direction,
                'trading_partner_id' => $tradingPartnerId,
                'inbound_connection_id' => $inboundConnectionId,
                'outbound_connection_id' => $outboundConnectionId,
                'received_via' => $receivedVia,
                'format' => $format,
                'original_filename' => $originalFilename,
                'file_sha256' => $sha256,
                'payload_disk' => $disk,
                'payload_path' => $payloadPath,
                'dscsa_affirm' => false,
                'status' => 'received',
                'notes' => $notes,
                'reprocess_count' => 0,
                'event_count' => 0,
                'epc_count' => 0,
                'received_at' => now(),
            ]);
        });

        if (($dispatch || $sync) && ! $this->shouldSkipOutboundProcessing($document, $receivedVia)) {
            try {
                $this->dispatchProcess($document, $sync || Queue::getDefaultDriver() === 'sync');
            } catch (Throwable $e) {
                $document->forceFill([
                    'status' => 'error',
                    'error_message' => Str::limit($e->getMessage(), 2000),
                ])->save();

                throw $e;
            }
        }

        return $document->refresh();
    }

    private function shouldSkipOutboundProcessing(EpcisDocument $document, ?EpcisReceivedVia $receivedVia): bool
    {
        return $document->direction === 'outbound' && $receivedVia === EpcisReceivedVia::Api;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function resolveReceivedVia(
        array $meta,
        string $direction,
        ?int $inboundConnectionId,
        ?string $notes,
    ): ?EpcisReceivedVia {
        $raw = $meta['received_via'] ?? null;
        if ($direction !== 'inbound') {
            if ($raw instanceof EpcisReceivedVia) {
                return $raw;
            }
            if (is_string($raw) && $raw !== '') {
                return EpcisReceivedVia::tryFrom($raw);
            }

            return null;
        }

        if ($raw instanceof EpcisReceivedVia) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $fromRaw = EpcisReceivedVia::tryFrom($raw);
            if ($fromRaw !== null) {
                return $fromRaw;
            }
        }

        $fromNotes = EpcisReceivedVia::tryFromNotes($notes);
        if ($fromNotes !== null) {
            return $fromNotes;
        }

        if ($inboundConnectionId !== null) {
            return EpcisReceivedVia::HttpsWebhook;
        }

        // Untagged inbound (tests/CLI without explicit channel) stays off the catalog.
        return EpcisReceivedVia::Cli;
    }

    private function storePayloadStream(string $disk, string $payloadPath, string $absolutePath, string $format = EpcisSchemaVersion::FORMAT_XML): void
    {
        $stream = fopen($absolutePath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Unable to read EPCIS payload: {$absolutePath}");
        }

        $isS3 = config("filesystems.disks.{$disk}.driver") === 's3';
        // Do not set S3 object ACL/visibility — buckets with Object Ownership
        // "Bucket owner enforced" reject PutObjectAcl / setVisibility.
        $contentType = EpcisSchemaVersion::contentTypeForFormat($format);
        $options = array_filter([
            'ContentType' => $isS3 ? $contentType : null,
        ]);

        try {
            $filesystem = Storage::disk($disk);
            if (method_exists($filesystem, 'writeStream')) {
                $written = $filesystem->writeStream($payloadPath, $stream, $options);
                if ($written === false) {
                    throw new \RuntimeException("Unable to store EPCIS XML payload at {$payloadPath}");
                }

                return;
            }

            $filesystem->put($payloadPath, $stream, $options);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function dispatchProcess(EpcisDocument $document, bool $sync): void
    {
        $tenant = tenant();
        if ($tenant === null) {
            throw new \RuntimeException('ReceiveEpcisUpload requires an initialized tenant.');
        }

        if (config('tracepharma.epcis_jobs.enabled') && $document->direction === 'inbound') {
            app(EnqueueInboundEpcisJob::class)->handle($document, $sync);

            return;
        }

        $job = new ProcessEpcisDocumentJob($tenant, (int) $document->getKey());

        if ($sync) {
            // Calling handle() directly skips the job's WithoutOverlapping queue
            // middleware, so an equivalent lock is taken here to keep a concurrent
            // reprocess of the same document from racing this synchronous run.
            EpcisCacheLock::store()->lock($this->epcisProcessLockKey($document), 600)->block(30, function () use ($job): void {
                $job->handle(app(EpcisIngestionService::class));
            });

            return;
        }

        ProcessEpcisDocumentJob::dispatch($tenant, (int) $document->getKey());
    }

    private function epcisUploadHashLockKey(string $direction, string $sha256): string
    {
        $tenantId = (string) (tenant()?->getKey() ?? 'unknown');

        return 'epcis-upload-hash:'.$tenantId.':'.$direction.':'.$sha256;
    }

    private function epcisProcessLockKey(EpcisDocument $document): string
    {
        $tenantId = (string) (tenant()?->getKey() ?? 'unknown');

        return 'epcis-process:'.$tenantId.':'.$document->getKey();
    }
}
