<?php

declare(strict_types=1);

namespace App\Jobs\Exports;

use App\Models\DataExport;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TrackTraceExportReadyMail;
use App\Services\Exports\TrackTraceExportQuery;
use App\Services\Exports\TrackTracePdfExporter;
use App\Support\Tenancy\TenantRunner;
use DomainException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

class ProcessTrackTraceExportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $exportId,
    ) {}

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->exportId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(
        TrackTraceExportQuery $exportQuery,
        TrackTracePdfExporter $exporter,
    ): void {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        TenantRunner::run($tenant, function () use ($exportQuery, $exporter): void {
            $export = DataExport::query()->findOrFail($this->exportId);

            if ($export->status?->isTerminal()) {
                return;
            }

            $export->markProcessing();

            try {
                $isPortal = $exportQuery->isPortalExport($export);
                $user = null;

                if ($isPortal) {
                    if (! filled($export->notify_email)) {
                        throw new DomainException('Portal exports require a notify email for the download link.');
                    }

                    $exportQuery->assertDocumentReady($export, null);
                } else {
                    $user = $exportQuery->resolveRequestingUser($export);

                    $rowCount = $exportQuery->countForExport($export, $user);
                    $exportQuery->assertExportableRowCount($rowCount);
                }

                $disk = (string) config('tracepharma.exports.disk', 'tenant_exports');
                $path = $export->storageObjectKey();

                $result = $exporter->exportToStorage($export, $user, $disk, $path);

                if ($isPortal) {
                    $maxRows = max(1, (int) config('tracepharma.exports.max_rows', 500_000));
                    if ($result['row_count'] === 0) {
                        throw new DomainException('No serialized units match the export criteria.');
                    }
                    if ($result['row_count'] > $maxRows) {
                        throw new DomainException(
                            "Export would return {$result['row_count']} rows, which exceeds the limit of {$maxRows}. Refine your filters.",
                        );
                    }
                }

                $export->markCompleted($result['row_count'], $result['disk'], $result['path']);

                $this->notifyRecipient($export->fresh() ?? $export);
            } catch (DomainException $exception) {
                $export->markFailed($exception->getMessage());
            }
        });
    }

    public function failed(?Throwable $e): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            Log::error('Track-and-trace export failed: tenant not found.', [
                'tenant_id' => $this->tenantId,
                'export_id' => $this->exportId,
                'error' => $e?->getMessage(),
            ]);

            return;
        }

        TenantRunner::run($tenant, function () use ($e): void {
            $export = DataExport::query()->find($this->exportId);

            if ($export === null || $export->status?->isTerminal()) {
                return;
            }

            $export->markFailed(Str::limit($e?->getMessage() ?? 'Export job failed.', 2000));
        });
    }

    private function notifyRecipient(DataExport $export): void
    {
        $user = $export->requested_by_user_id !== null
            ? User::query()->find($export->requested_by_user_id)
            : null;

        if ($user !== null) {
            $user->notify(new TrackTraceExportReadyMail($export, $this->tenantId));

            $alternateEmail = filled($export->notify_email) ? (string) $export->notify_email : null;

            if ($alternateEmail !== null && strcasecmp($alternateEmail, (string) $user->email) !== 0) {
                Notification::route('mail', $alternateEmail)
                    ->notify(TrackTraceExportReadyMail::mailOnly($export, $this->tenantId));
            }

            return;
        }

        $email = filled($export->notify_email)
            ? (string) $export->notify_email
            : null;

        if ($email === null) {
            return;
        }

        Notification::route('mail', $email)
            ->notify(TrackTraceExportReadyMail::mailOnly($export, $this->tenantId));
    }
}
