<?php

declare(strict_types=1);

namespace App\Actions\EpcisJobs;

use App\Actions\Epcis\PrepareOutboundEpcisForRetransmit;
use App\Models\EpcisJob;
use App\Support\Epcis\EpcisStoragePath;
use App\Support\EpcisJobs\EpcisJobLogger;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Prepare outbound payload before requeue: shipping rebuilds TI from the current
 * open hierarchy (new InstanceIdentifier + filename); other outbound remints
 * identity only. Then asserts the prepared file is readable.
 *
 * Pass skipPrepare=true when Retry Transmit already ran {@see PrepareOutboundEpcisForRetransmit}.
 */
class RebuildEpcisJobPayload
{
    public function __construct(
        private readonly EpcisJobLogger $logger,
        private readonly PrepareOutboundEpcisForRetransmit $prepareOutboundEpcisForRetransmit,
    ) {}

    public function handle(EpcisJob $job, bool $skipPrepare = false): void
    {
        $job = $job->fresh(['document']) ?? $job;

        if (! $skipPrepare) {
            $document = $job->document;
            if ($document === null) {
                throw new RuntimeException('Outbound payload is missing; cannot requeue.');
            }

            if ($document->direction === 'outbound') {
                $prepared = $this->prepareOutboundEpcisForRetransmit->handle($document);
                $this->logger->info(
                    $job,
                    sprintf(
                        'Prepared outbound payload for requeue (%s): %s → %s',
                        $prepared['mode'],
                        $prepared['old_uuid'] ?: '(none)',
                        $prepared['new_uuid'],
                    ),
                );
                $job = $job->fresh(['document']) ?? $job;
            }
        } else {
            $this->logger->info($job, 'Skipping payload prepare; already reminted/rebuilt for Retry Transmit.');
        }

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
            'Requeue will transmit the prepared payload.',
        );
    }
}
