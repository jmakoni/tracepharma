<?php

declare(strict_types=1);

namespace App\Actions\EpcisJobs;

use App\Models\EpcisJob;
use App\Support\Epcis\EpcisStoragePath;
use App\Support\EpcisJobs\EpcisJobLogger;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Verify the outbound payload is still readable before requeue.
 *
 * Requeue is a retransmission, never a regeneration: the bytes already on disk are
 * the bytes a trading partner may have seen, and document_uuid / file_sha256 are the
 * DSCSA audit trail for them. Shipping payloads are no exception — a full-history
 * rebuild is only reachable through tracepharma:rebuild-outbound-shipping-epcis,
 * which is an explicit operator decision rather than a side effect of a retry.
 */
class RebuildEpcisJobPayload
{
    public function __construct(
        private readonly EpcisJobLogger $logger,
    ) {}

    public function handle(EpcisJob $job): void
    {
        $job = $job->fresh(['document']) ?? $job;

        $this->assertExistingPayload($job);
    }

    private function assertExistingPayload(EpcisJob $job): void
    {
        $document = $job->document;

        if ($document === null || blank($document->payload_path)) {
            throw new RuntimeException('Outbound payload is missing; cannot requeue.');
        }

        $disk = method_exists($document, 'payloadFilesystemDisk')
            ? $document->payloadFilesystemDisk()
            : (string) ($document->payload_disk ?: 'local');
        $path = EpcisStoragePath::onDisk($disk, (string) $document->payload_path);

        if (! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('Outbound payload file is missing on disk; cannot requeue.');
        }

        $this->logger->info(
            $job,
            'Requeue will transmit the existing payload unchanged.',
        );
    }
}
