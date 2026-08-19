<?php

namespace App\Jobs;

use App\Models\Epcis\EpcisException;
use App\Models\Tenant;
use App\Services\Exceptions\ExceptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

    public function __construct(
        public string $tenantId,
        public int $epcisExceptionId,
    ) {}

    public function handle(ExceptionService $exceptions): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $tenant->run(function () use ($exceptions): void {
            $signal = EpcisException::query()->find($this->epcisExceptionId);

            if ($signal === null || $signal->case_id !== null) {
                return;
            }

            $allow = config('tracepharma.exceptions.auto_promote_types', []);

            if ($allow !== [] && ! in_array($signal->exception_type, $allow, true)) {
                return;
            }

            if ($allow === []) {
                return;
            }

            $signal->loadMissing('document');
            $exceptions->createFromSignal($signal);
        });
    }
}
