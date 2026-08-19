<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Admin;
use App\Notifications\TenantIntegrityAlert;
use App\Support\TenantIntegrityAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class TenantHealthAlertCommand extends Command
{
    protected $signature = 'tracepharma:tenant-health-alert
                            {--throttle=24 : Hours to suppress repeat notifications for the same issue set}
                            {--force : Send notifications even if recently alerted}';

    protected $description = 'Alert platform admins when tenant integrity issues are detected';

    public function handle(TenantIntegrityAuditor $auditor): int
    {
        $report = $auditor->audit();

        if ($report['healthy']) {
            $this->info('Tenant integrity: OK. No alert sent.');

            return SymfonyCommand::SUCCESS;
        }

        $detached = $report['detached_tenants'];
        $withoutDomains = $report['tenants_without_domains'];

        $signature = md5((string) json_encode([
            'detached' => array_column($detached, 'id'),
            'without_domains' => array_column($withoutDomains, 'id'),
        ]));
        $cacheKey = 'tenant_health_alert:'.$signature;
        $throttleHours = max(0, (int) $this->option('throttle'));
        $force = (bool) $this->option('force');

        if (! $force && $throttleHours > 0 && Cache::has($cacheKey)) {
            $this->warn(sprintf(
                'Tenant integrity issues present (%d detached, %d without domains) but already alerted within %dh; skipping notification.',
                count($detached),
                count($withoutDomains),
                $throttleHours,
            ));

            return SymfonyCommand::FAILURE;
        }

        Log::warning('Tenant integrity issues detected', [
            'detached_tenants' => $detached,
            'tenants_without_domains' => $withoutDomains,
        ]);

        $notification = new TenantIntegrityAlert($report);
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
            'Tenant integrity alert: %d detached, %d without domains. Notified %d recipient(s); logged warning.',
            count($detached),
            count($withoutDomains),
            $recipients,
        ));

        if ($recipients === 0) {
            $this->comment('No admin recipients and no OPS_ALERT_EMAIL configured — alert was logged only.');
        }

        return SymfonyCommand::FAILURE;
    }
}
