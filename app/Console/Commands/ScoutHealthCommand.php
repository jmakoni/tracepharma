<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\Product;
use App\Models\Tenant;
use App\Notifications\ScoutHealthAlert;
use App\Support\Scout\TenantScoutCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

class ScoutHealthCommand extends Command
{
    protected $signature = 'tracepharma:scout-health
        {--tenant= : Optional tenant id to probe products index with an empty search}
        {--alert : Notify OPS_ALERT_EMAIL and platform admins on failure}
        {--throttle=24 : Hours to suppress repeat alert notifications for the same issue}
        {--force : Send alert even if recently notified}';

    protected $description = 'Check Meilisearch availability and optionally probe a tenant Scout index';

    public function handle(): int
    {
        if (! TenantScoutCatalog::usesMeilisearch()) {
            $driver = (string) config('scout.driver', 'collection');
            $this->info("Scout driver [{$driver}] is not Meilisearch; skipping health check.");

            return SymfonyCommand::SUCCESS;
        }

        $issues = [];

        $host = rtrim((string) config('scout.meilisearch.host', 'http://localhost:7700'), '/');

        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->get("{$host}/health");

            if (! $response->successful()) {
                $issues[] = "Meilisearch health endpoint returned HTTP {$response->status()}.";
            } elseif (($response->json('status') ?? null) !== 'available') {
                $issues[] = 'Meilisearch reported status: '.json_encode($response->json()) ?: 'unknown';
            } else {
                $this->info('Meilisearch health: available.');
            }
        } catch (Throwable $exception) {
            $issues[] = 'Meilisearch health check failed: '.$exception->getMessage();
        }

        $tenantId = $this->option('tenant');

        if (filled($tenantId)) {
            $tenant = Tenant::query()->find($tenantId);

            if ($tenant === null) {
                $issues[] = "Tenant [{$tenantId}] was not found.";
            } else {
                $issues = array_merge($issues, $this->probeTenantIndex($tenant));
            }
        } elseif ($issues === []) {
            $tenant = Tenant::query()
                ->where('status', 'active')
                ->orderBy('id')
                ->first()
                ?? Tenant::query()->orderBy('id')->first();

            if ($tenant !== null) {
                $issues = array_merge($issues, $this->probeTenantIndex($tenant));
            }
        }

        if ($issues === []) {
            return SymfonyCommand::SUCCESS;
        }

        foreach ($issues as $issue) {
            $this->error($issue);
        }

        Log::warning('Scout health check failed', ['issues' => $issues]);

        if ((bool) $this->option('alert')) {
            $this->sendAlert($issues);
        }

        return SymfonyCommand::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function probeTenantIndex(Tenant $tenant): array
    {
        $alreadyOnTenant = tenancy()->initialized
            && tenant() instanceof Tenant
            && tenant()->getKey() === $tenant->getKey();

        if (! $alreadyOnTenant) {
            tenancy()->initialize($tenant);
        }

        try {
            Product::search('')->take(1)->raw();

            $this->info("Tenant products index probe OK: {$tenant->id} ({$tenant->name}).");

            return [];
        } catch (Throwable $exception) {
            return [
                "Tenant products index probe failed for {$tenant->id}: {$exception->getMessage()}",
            ];
        } finally {
            if (! $alreadyOnTenant) {
                tenancy()->end();
            }
        }
    }

    /**
     * @param  list<string>  $issues
     */
    private function sendAlert(array $issues): void
    {
        $signature = md5((string) json_encode($issues));
        $cacheKey = 'scout_health_alert:'.$signature;
        $throttleHours = max(0, (int) $this->option('throttle'));
        $force = (bool) $this->option('force');

        if (! $force && $throttleHours > 0 && Cache::has($cacheKey)) {
            $this->warn("Scout health alert suppressed (already notified within {$throttleHours}h).");

            return;
        }

        $notification = new ScoutHealthAlert($issues);
        $recipients = 0;

        $admins = Admin::query()->whereNotNull('email')->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, $notification);
            $recipients += $admins->count();
        }

        $opsEmail = config('tracepharma.ops_alert_email');

        if (is_string($opsEmail) && $opsEmail !== '') {
            Notification::route('mail', $opsEmail)->notify($notification);
            $recipients++;
        }

        if ($throttleHours > 0) {
            Cache::put($cacheKey, now()->toIso8601String(), now()->addHours($throttleHours));
        }

        $this->warn("Scout health alert sent to {$recipients} recipient(s).");

        if ($recipients === 0) {
            $this->comment('No admin recipients and no OPS_ALERT_EMAIL configured — alert was logged only.');
        }
    }
}
