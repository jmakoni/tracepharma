<?php

namespace App\Actions\Epcis;

use App\Actions\EpcisJobs\EnqueueInboundEpcisJob;
use App\Jobs\ProcessEpcisDocumentJob;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisUnmatchedGln;
use App\Models\Receiving\ReceivingSession;
use App\Services\Epcis\EpcisIngestionService;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Epcis\EpcisCacheLock;
use App\Support\EpcisJobs\SyncInboundEpcisJobFromDocument;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queue re-ingestion; on success ProcessEpcisDocument writes a new ingest_generation
 * and prunes superseded generations, keeping only the active projection.
 */
final class ReprocessEpcisDocument
{
    public function handle(
        EpcisDocument $document,
        bool $sync = false,
        bool $force = false,
        bool $authorizeExceptionsRole = true,
    ): EpcisDocument {
        if ($authorizeExceptionsRole) {
            $actor = auth()->user();
            $allowed = JobRoleAccess::allowsForActor(Permissions::NavExceptions, $actor)
                || JobRoleAccess::allowsForActor(Permissions::NavIntegrations, $actor);

            if (! $allowed) {
                throw new DomainException('Reprocessing is not authorized for your job role.');
            }
        }

        $document = DB::transaction(function () use ($document, $force): EpcisDocument {
            $document = EpcisDocument::query()
                ->whereKey($document->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($document->status, ['parsed', 'validated', 'error', 'received'], true)) {
                throw new DomainException(
                    "EPCIS document {$document->getKey()} cannot be reprocessed from status [{$document->status}].",
                );
            }

            if (! $force && Schema::hasTable('receiving_sessions')) {
                $activeReceiving = ReceivingSession::query()
                    ->where('epcis_document_id', $document->getKey())
                    ->whereIn('status', ['open', 'in_progress'])
                    ->exists();

                if ($activeReceiving) {
                    throw new DomainException(
                        "EPCIS document {$document->getKey()} has an open or in-progress receiving session; pass force=true to reprocess anyway.",
                    );
                }
            }

            return $document;
        });

        app(SyncInboundEpcisJobFromDocument::class)->reconcileAndArchiveForReprocess($document);

        if (Schema::hasTable('epcis_unmatched_glns')) {
            EpcisUnmatchedGln::query()->where('document_id', $document->getKey())->delete();
        }

        $previousStatus = $document->status;

        $document->forceFill([
            'status' => 'received',
            'error_message' => null,
            'reprocess_count' => (int) $document->reprocess_count + 1,
        ])->save();

        if (function_exists('activity')) {
            activity()
                ->performedOn($document)
                ->withProperties([
                    'reprocess_count' => (int) $document->reprocess_count,
                    'force' => $force,
                    'sync' => $sync,
                ])
                ->log('epcis_document_reprocessed');
        }

        try {
            $tenant = tenant();
            if ($tenant === null) {
                throw new \RuntimeException('ReprocessEpcisDocument requires an initialized tenant.');
            }

            $runSync = $sync || Queue::getDefaultDriver() === 'sync';

            if (config('tracepharma.epcis_jobs.enabled') && $document->direction === 'inbound') {
                app(EnqueueInboundEpcisJob::class)->handle($document, $runSync);

                return $document->refresh();
            }

            $job = new ProcessEpcisDocumentJob($tenant, (int) $document->getKey());

            if ($runSync) {
                // Calling handle() directly skips the job's WithoutOverlapping queue
                // middleware, so an equivalent lock is taken here to keep a concurrent
                // reprocess of the same document from racing this synchronous run.
                EpcisCacheLock::lock($this->epcisProcessLockKey($document), 600)->block(30, function () use ($job): void {
                    $job->handle(app(EpcisIngestionService::class));
                });
            } else {
                ProcessEpcisDocumentJob::dispatch($tenant, (int) $document->getKey());
            }
        } catch (Throwable $e) {
            $message = Str::limit($e->getMessage(), 2000);

            if ($document->direction === 'outbound') {
                $document->forceFill([
                    'status' => $previousStatus,
                    'transmission_status' => 'failed',
                    'error_message' => $message,
                ])->save();
            } else {
                $document->forceFill([
                    'status' => 'error',
                    'error_message' => $message,
                ])->save();
            }

            throw $e;
        }

        return $document->refresh();
    }

    private function epcisProcessLockKey(EpcisDocument $document): string
    {
        $tenantId = (string) (tenant()?->getKey() ?? 'unknown');

        return 'epcis-process:'.$tenantId.':'.$document->getKey();
    }
}
