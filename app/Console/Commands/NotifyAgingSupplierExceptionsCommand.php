<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Exceptions\SendDscsaExceptionEmail;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Tenant;
use App\Support\Exceptions\InvestigatorSlaClock;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

/**
 * Push-notify trading partners for open aging exception cases (portal + email).
 * Does not parse inbound email replies — that stays pilot-gated.
 */
class NotifyAgingSupplierExceptionsCommand extends Command
{
    protected $signature = 'exceptions:notify-aging-suppliers
                            {--tenant= : Limit to a single tenant id}
                            {--dry-run : Report matches without sending mail}
                            {--force : Ignore cooldown since last partner email}';

    protected $description = 'Email trading partners for open aging exception cases and ensure portal visibility';

    public function handle(SendDscsaExceptionEmail $sendEmail, InvestigatorSlaClock $clock): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $tenantId = $this->option('tenant');
        $sent = 0;
        $skipped = 0;
        $failed = 0;

        $agingDays = max(1, (int) config('tracepharma.supplier_exception_notify.aging_days', 3));
        $cooldownHours = max(1, (int) config('tracepharma.supplier_exception_notify.cooldown_hours', 72));

        $query = Tenant::query()->where('status', 'active')->orderBy('name');

        if (is_string($tenantId) && $tenantId !== '') {
            $query->where('id', $tenantId);
        }

        $query->cursor()->each(function (Tenant $tenant) use (
            $sendEmail,
            $clock,
            $dryRun,
            $force,
            $agingDays,
            $cooldownHours,
            &$sent,
            &$skipped,
            &$failed,
        ): void {
            try {
                TenantRunner::run($tenant, function () use (
                    $tenant,
                    $sendEmail,
                    $clock,
                    $dryRun,
                    $force,
                    $agingDays,
                    $cooldownHours,
                    &$sent,
                    &$skipped,
                ): void {
                    $cases = ExceptionCase::query()
                        ->open()
                        ->whereNotNull('trading_partner_id')
                        ->where('created_at', '<', now()->subDays($agingDays))
                        ->whereHas('tradingPartner', function ($partners): void {
                            $partners->where('is_active', true)->whereNotNull('email')->where('email', '!=', '');
                        })
                        ->with('tradingPartner:id,name,email,is_active')
                        ->orderBy('id')
                        ->limit(200)
                        ->get();

                    foreach ($cases as $case) {
                        if (! $force) {
                            $last = $clock->lastSupplierEmailAt($case);
                            if ($last !== null && $last->greaterThan(now()->subHours($cooldownHours))) {
                                $skipped++;

                                continue;
                            }
                        }

                        $this->line(sprintf(
                            '%s%s: case #%d partner=%s',
                            $dryRun ? '[dry-run] ' : '',
                            $tenant->name,
                            $case->getKey(),
                            $case->tradingPartner?->email ?? '—',
                        ));

                        if ($dryRun) {
                            $sent++;

                            continue;
                        }

                        $result = $sendEmail->execute($case, null);

                        if (($result['sent'] ?? false) === true) {
                            $sent++;
                        } else {
                            $skipped++;
                            $this->warn('  skip: '.($result['error'] ?? 'not sent'));
                        }
                    }
                });
            } catch (Throwable $exception) {
                $failed++;
                $this->error("{$tenant->name}: {$exception->getMessage()}");
            } finally {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        });

        $this->info(sprintf(
            'Aging supplier notify complete. sent=%d skipped=%d failed=%d%s',
            $sent,
            $skipped,
            $failed,
            $dryRun ? ' (dry-run)' : '',
        ));

        return $failed > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }
}
