<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SsccPoolLowWaterNotification;
use App\Notifications\SsccRangeLowThresholdNotification;
use App\Services\Labeling\SsccNumberRangeMonitorService;
use App\Services\Labeling\SsccPoolMonitorService;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;

class CheckSsccPoolLevelsCommand extends Command
{
    protected $signature = 'sscc:check-pool-levels {--tenant=}';

    protected $description = 'Alert when SSCC serial pools or number ranges fall below configured thresholds';

    public function handle(
        SsccPoolMonitorService $poolMonitor,
        SsccNumberRangeMonitorService $rangeMonitor,
    ): int {
        $tenantId = $this->option('tenant');
        $alertedPools = 0;
        $alertedRanges = 0;

        $tenants = $tenantId
            ? Tenant::query()->where('id', $tenantId)->get()
            : Tenant::query()->where('status', 'active')->get();

        foreach ($tenants as $tenant) {
            if (! $tenant->features()->supportsSsccLabeling()) {
                continue;
            }

            TenantRunner::run($tenant, function () use ($poolMonitor, $rangeMonitor, &$alertedPools, &$alertedRanges): void {
                $owners = User::query()
                    ->role([TenantRole::Owner->value])
                    ->get();

                $pools = $poolMonitor->lowWaterPools();
                if ($pools !== []) {
                    $owners->each(function (User $user) use ($pools): void {
                        $user->notify(new SsccPoolLowWaterNotification($pools));
                    });
                    $alertedPools += count($pools);
                }

                $ranges = $rangeMonitor->rangesNeedingAlert();
                if ($ranges !== [] && $owners->isNotEmpty()) {
                    $owners->each(function (User $user) use ($ranges): void {
                        $user->notify(new SsccRangeLowThresholdNotification($ranges));
                    });
                    $rangeMonitor->markNotified(
                        array_map(static fn (array $row): int => (int) $row['range_id'], $ranges),
                    );
                    $alertedRanges += count($ranges);
                }
            });
        }

        $this->info("Alerted on {$alertedPools} low-water SSCC pool(s) and {$alertedRanges} number range(s).");

        return self::SUCCESS;
    }
}
