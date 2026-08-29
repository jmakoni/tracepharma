<?php

namespace App\Jobs\Vrs;

use App\Actions\Vrs\RunProductVerification;
use App\Exceptions\VrsConfigurationException;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Vrs\VrsLogCorrelation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class RunProductVerificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public string $tenantId,
        public string $scan,
        public ?int $actorId = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(): void
    {
        Tenant::query()->findOrFail($this->tenantId)->run(function (): void {
            $actor = $this->actorId !== null
                ? User::query()->find($this->actorId)
                : null;

            try {
                app(RunProductVerification::class)->handle($this->scan, $actor);
            } catch (InvalidArgumentException $e) {
                Log::info('VRS verify skipped for invalid scan', [
                    'tenant_id' => $this->tenantId,
                    'scan_hash' => VrsLogCorrelation::scanHash($this->scan),
                    'message' => $e->getMessage(),
                ]);
            } catch (VrsConfigurationException $e) {
                Log::warning('VRS verify skipped — driver not configured', [
                    'tenant_id' => $this->tenantId,
                    'scan_hash' => VrsLogCorrelation::scanHash($this->scan),
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['tenant:'.$this->tenantId, 'vrs-verify'];
    }
}
