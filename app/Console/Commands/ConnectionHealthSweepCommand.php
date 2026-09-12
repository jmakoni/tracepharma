<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TenantRole;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ConnectionFailureStreakAlert;
use App\Support\Integrations\ConnectionHealthTracker;
use App\Support\Tenancy\TenantRunner;
use App\Support\TenantSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Throwable;

class ConnectionHealthSweepCommand extends Command
{
    protected $signature = 'connections:health-sweep
        {--tenant= : Limit to a single tenant ID}';

    protected $description = 'Alert on connection failure streaks and optionally auto-pause runaway connections';

    public function handle(): int
    {
        $tenants = Tenant::query()
            ->where('status', 'active')
            ->when($this->option('tenant'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        $alerted = 0;
        $paused = 0;

        foreach ($tenants as $tenant) {
            [$a, $p] = TenantRunner::run($tenant, fn (): array => $this->sweepTenant());

            $alerted += $a;
            $paused += $p;
        }

        $this->info("Health sweep complete: {$alerted} alert(s), {$paused} auto-pause(s).");

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int} [alerts sent, connections auto-paused]
     */
    private function sweepTenant(): array
    {
        $alerted = 0;
        $paused = 0;
        $autoPause = TenantSettings::forTenant(tenant())->autoPauseOnFailureStreak();

        $connections = collect()
            ->merge(OutboundConnection::query()->where('consecutive_failures', '>=', ConnectionHealthTracker::ALERT_THRESHOLD)->get()
                ->map(fn (OutboundConnection $c): array => [$c, 'outbound']))
            ->merge(InboundConnection::query()->where('consecutive_failures', '>=', ConnectionHealthTracker::ALERT_THRESHOLD)->get()
                ->map(fn (InboundConnection $c): array => [$c, 'inbound']));

        foreach ($connections as [$connection, $direction]) {
            $shouldPause = $autoPause
                && $connection->is_active
                && $connection->consecutive_failures >= ConnectionHealthTracker::AUTO_PAUSE_THRESHOLD;

            // Throttle repeat alerts to once per 6h per connection+streak bucket.
            $cacheKey = sprintf(
                'connection_failure_alert:%s:%d:%d',
                $direction,
                (int) $connection->getKey(),
                intdiv((int) $connection->consecutive_failures, ConnectionHealthTracker::ALERT_THRESHOLD),
            );

            if (Cache::has($cacheKey) && ! $shouldPause) {
                continue;
            }

            if ($shouldPause) {
                $connection->forceFill(['is_active' => false])->save();

                activity()
                    ->performedOn($connection)
                    ->withProperties([
                        'consecutive_failures' => $connection->consecutive_failures,
                        'last_error' => $connection->last_error,
                    ])
                    ->log('connection_auto_paused_failure_streak');

                $paused++;
            }

            $this->notifyOwners($connection, $direction, $shouldPause);
            Cache::put($cacheKey, now()->toIso8601String(), now()->addHours(6));
            $alerted++;
        }

        return [$alerted, $paused];
    }

    private function notifyOwners(OutboundConnection|InboundConnection $connection, string $direction, bool $autoPaused): void
    {
        try {
            $owners = User::role(TenantRole::Owner->value)->get();
        } catch (RoleDoesNotExist) {
            return;
        }

        if ($owners->isEmpty()) {
            return;
        }

        try {
            Notification::send($owners, new ConnectionFailureStreakAlert(
                (string) $connection->name,
                $direction,
                (int) $connection->consecutive_failures,
                $connection->last_error !== null ? mb_substr((string) $connection->last_error, 0, 300) : null,
                $autoPaused,
            ));
        } catch (Throwable) {
            // Alerting must never break the sweep.
        }
    }
}
