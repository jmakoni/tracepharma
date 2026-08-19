<?php

declare(strict_types=1);

namespace App\Actions\EpcisJobs;

use App\Actions\Epcis\ReprocessEpcisDocument;
use App\Enums\EpcisJobKind;
use App\Enums\EpcisJobStatus;
use App\Models\EpcisJob;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\EpcisJobs\EpcisJobLogger;
use RuntimeException;

final class RequeueEpcisJob
{
    public function __construct(
        private readonly RebuildEpcisJobPayload $rebuildPayload,
        private readonly EnqueueEpcisJob $enqueue,
        private readonly EnqueueInboundEpcisJob $enqueueInbound,
        private readonly ReprocessEpcisDocument $reprocessInbound,
        private readonly EpcisJobLogger $logger,
    ) {}

    public function handle(EpcisJob $job, ?int $requestedBy = null): EpcisJob
    {
        if (! JobRoleAccess::allowsAny(Permissions::NavIntegrations, Permissions::NavExceptions)) {
            throw new RuntimeException('Integrations or Exceptions are not authorized for your job role.');
        }

        $job = $job->fresh(['document']) ?? $job;

        if (! in_array($job->status, [EpcisJobStatus::Error, EpcisJobStatus::Cancelled], true)) {
            throw new RuntimeException('Only error or cancelled jobs can be requeued.');
        }

        $document = $job->document;

        if ($document === null) {
            throw new RuntimeException('Job has no EPCIS document to requeue.');
        }

        try {
            $this->rebuildPayload->handle($job);
        } catch (\Throwable $e) {
            $this->logger->error($job, 'Rebuild/reprocess prep failed: '.$e->getMessage());
            $job->forceFill([
                'status' => EpcisJobStatus::Error,
                'error_message' => $e->getMessage(),
            ])->save();

            throw $e;
        }

        $this->logger->info($job, 'Requeue requested; creating a new job receipt.');

        if ($job->kind === EpcisJobKind::InboundProcess) {
            // Reset document then enqueue a new inbound ledger + ProcessEpcisDocumentJob.
            $this->reprocessInbound->handle(
                $document->fresh() ?? $document,
                false,
                force: false,
                authorizeExceptionsRole: true,
            );

            $newJob = EpcisJob::query()
                ->where('epcis_document_id', $document->getKey())
                ->where('kind', EpcisJobKind::InboundProcess->value)
                ->whereNull('archived_at')
                ->latest('id')
                ->first();

            if ($newJob === null) {
                return $this->enqueueInbound->handle($document->fresh() ?? $document, false, $requestedBy);
            }

            return $newJob;
        }

        return $this->enqueue->handle($document->fresh() ?? $document, $requestedBy, forceRequeue: true);
    }
}
