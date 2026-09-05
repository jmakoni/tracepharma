<?php

namespace App\Jobs;

use App\Models\Epcis\EpcisException;
use App\Models\Tenant;
use App\Services\Exceptions\ExceptionService;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Optional post-ingest promote of a signal to an investigation case.
 * Enable type codes via config('tracepharma.exceptions.auto_promote_types').
 * Never call from inside the EPCIS ingest DB transaction.
 */
class PromoteEpcisExceptionToCaseJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public string $tenantId,
        public int $epcisExceptionId,
    ) {}

    public function handle(ExceptionService $exceptions): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            Log::warning('PromoteEpcisExceptionToCaseJob skipped: tenant missing.', [
                'tenant_id' => $this->tenantId,
                'epcis_exception_id' => $this->epcisExceptionId,
            ]);

            return;
        }

        TenantRunner::run($tenant, function () use ($exceptions): void {
            $signal = EpcisException::query()->find($this->epcisExceptionId);

            if ($signal === null || $signal->case_id !== null) {
                return;
            }

            $allow = config('tracepharma.exceptions.auto_promote_types', []);

            if ($allow !== []) {
                $canonicalType = ExceptionService::legacySignalTypeMap()[$signal->exception_type]
                    ?? $signal->exception_type;

                $allowed = in_array($signal->exception_type, $allow, true)
                    || in_array($canonicalType, $allow, true);

                if (! $allowed) {
                    Log::info('PromoteEpcisExceptionToCaseJob skipped: type not allowed.', [
                        'exception_type' => $signal->exception_type,
                        'allowed' => $allow,
                    ]);

                    return;
                }
            }

            if ($allow === []) {
                return;
            }

            $signal->loadMissing('document');
            $exceptions->createFromSignal($signal);
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::error('PromoteEpcisExceptionToCaseJob failed.', [
            'tenant_id' => $this->tenantId,
            'epcis_exception_id' => $this->epcisExceptionId,
            'message' => $exception->getMessage(),
        ]);
    }
}
