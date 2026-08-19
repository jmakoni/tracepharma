<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\Tenant;
use App\Notifications\AggregationLinkForeignKeyAlert;
use App\Support\AggregationLinkForeignKeyDoctor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class DoctorAggregationLinkFkCommand extends Command
{
    protected $signature = 'tracepharma:doctor-aggregation-link-fk
                            {--tenant=* : Tenant ID(s); default all}
                            {--fix : Run tenant migrations for tenants that still cascade on established_by_event_id}
                            {--alert : Notify OPS_ALERT_EMAIL and platform admins when cascade FK drift is detected}
                            {--throttle=24 : Hours to suppress repeat alert notifications for the same issue set}
                            {--force : Send alert even if recently notified}';

    protected $description = 'Detect tenants where aggregation_links.established_by_event_id still uses ON DELETE CASCADE';

    public function handle(AggregationLinkForeignKeyDoctor $doctor): int
    {
        $tenants = $this->resolveTenants();

        if ($tenants->isEmpty()) {
            $this->info('No matching tenants found.');

            return SymfonyCommand::SUCCESS;
        }

        $issues = $doctor->inspectTenants($tenants);
        $this->recordAudit($issues);

        if ($issues === []) {
            $this->info('All inspected tenants have dropped or non-cascade established_by_event_id foreign keys.');

            return SymfonyCommand::SUCCESS;
        }

        foreach ($issues as $issue) {
            $this->warn(sprintf(
                '[%s] %s: aggregation_links.established_by_event_id FK %s uses ON DELETE %s',
                $issue['tenant_id'],
                $issue['tenant_name'],
                $issue['constraint_name'],
                $issue['delete_rule'],
            ));
        }

        Log::warning('Aggregation link FK cascade drift detected', ['issues' => $issues]);

        if ((bool) $this->option('alert')) {
            $this->sendAlert($issues);
        }

        if (! (bool) $this->option('fix')) {
            $this->comment('Re-run with --fix to apply tenant migrations for the tenants listed above.');

            return SymfonyCommand::FAILURE;
        }

        $fixed = 0;
        $stillBroken = [];

        foreach ($issues as $issue) {
            $tenant = $tenants->firstWhere('id', $issue['tenant_id']);

            if (! $tenant instanceof Tenant) {
                continue;
            }

            $exitCode = Artisan::call('tenants:migrate', [
                '--tenants' => [$tenant->id],
                '--force' => true,
            ]);

            $this->output->write(Artisan::output());

            if ($exitCode !== SymfonyCommand::SUCCESS) {
                $stillBroken[] = $issue;

                continue;
            }

            if ($doctor->inspectTenant($tenant) === null) {
                $fixed++;
                $this->info("[{$tenant->id}] {$tenant->name}: FK cascade removed.");

                continue;
            }

            $stillBroken[] = $issue;
            $this->error("[{$tenant->id}] {$tenant->name}: migration ran but CASCADE FK is still present.");
        }

        $remainingIssues = $doctor->inspectTenants($tenants);
        $this->recordAudit($remainingIssues);

        if ($stillBroken !== []) {
            $this->error(sprintf(
                '%d tenant(s) still have CASCADE on established_by_event_id after --fix.',
                count($stillBroken),
            ));

            return SymfonyCommand::FAILURE;
        }

        $this->info("Fixed {$fixed} tenant(s).");

        return SymfonyCommand::SUCCESS;
    }

    /**
     * @param  list<array{tenant_id: string, tenant_name: string, constraint_name: string, delete_rule: string}>  $issues
     */
    private function recordAudit(array $issues): void
    {
        Cache::put(AggregationLinkForeignKeyDoctor::LAST_AUDIT_CACHE_KEY, [
            'issues' => $issues,
            'checked_at' => now()->toIso8601String(),
        ], now()->addDays(2));
    }

    /**
     * @param  list<array{tenant_id: string, tenant_name: string, constraint_name: string, delete_rule: string}>  $issues
     */
    private function sendAlert(array $issues): void
    {
        $signature = md5((string) json_encode($issues));
        $cacheKey = 'aggregation_link_fk_alert:'.$signature;
        $throttleHours = max(0, (int) $this->option('throttle'));
        $force = (bool) $this->option('force');

        if (! $force && $throttleHours > 0 && Cache::has($cacheKey)) {
            $this->warn(sprintf(
                'Aggregation link FK alert suppressed (already notified within %dh).',
                $throttleHours,
            ));

            return;
        }

        $notification = new AggregationLinkForeignKeyAlert($issues);
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

        $this->warn(sprintf(
            'Aggregation link FK alert sent to %d recipient(s).',
            $recipients,
        ));

        if ($recipients === 0) {
            $this->comment('No admin recipients and no OPS_ALERT_EMAIL configured — alert was logged only.');
        }
    }

    /**
     * @return Collection<int, Tenant>
     */
    private function resolveTenants(): Collection
    {
        /** @var list<string> $tenantIds */
        $tenantIds = $this->option('tenant');

        if ($tenantIds !== []) {
            return Tenant::query()->whereIn('id', $tenantIds)->orderBy('id')->get();
        }

        return Tenant::query()->orderBy('id')->get();
    }
}
