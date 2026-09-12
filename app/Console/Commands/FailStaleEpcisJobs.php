<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\EpcisJobs\ForceFailEpcisJob;
use App\Enums\EpcisJobStatus;
use App\Models\EpcisJob;
use App\Models\Tenant;
use App\Support\EpcisJobs\EpcisJobSla;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

class FailStaleEpcisJobs extends Command
{
    protected $signature = 'epcis:fail-stale-jobs {--tenant=}';

    protected $description = 'Force-fail EPCIS jobs stuck in Sending/Processing past worker SLA';

    private const RECOVERY_REASON = 'Force-failed by scheduled SLA recovery — worker did not complete within timeout.';

    public function handle(ForceFailEpcisJob $forceFail): int
    {
        $tenantId = $this->option('tenant');
        $failedJobs = 0;
        $errors = 0;

        $tenants = $tenantId
            ? Tenant::query()->where('id', $tenantId)->get()
            : Tenant::query()->where('status', 'active')->get();

        foreach ($tenants as $tenant) {
            try {
                TenantRunner::run($tenant, function () use ($forceFail, &$failedJobs): void {
                    EpcisJob::query()
                        ->notArchived()
                        ->whereIn('status', [EpcisJobStatus::Sending, EpcisJobStatus::Processing])
                        ->whereNotNull('started_at')
                        ->with('document')
                        ->orderBy('id')
                        ->each(function (EpcisJob $job) use ($forceFail, &$failedJobs): void {
                            $job = $job->fresh() ?? $job;

                            if (! EpcisJobSla::isPastSendingOrProcessingSla($job)) {
                                return;
                            }

                            if (! EpcisJobSla::canForceFail($job)) {
                                return;
                            }

                            $forceFail->handle($job, self::RECOVERY_REASON);
                            $failedJobs++;
                        });
                });
            } catch (Throwable $exception) {
                $errors++;
                $this->error("{$tenant->name}: {$exception->getMessage()}");
            } finally {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        }

        $this->info("Force-failed {$failedJobs} stale EPCIS job(s).");

        return $errors > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }
}
