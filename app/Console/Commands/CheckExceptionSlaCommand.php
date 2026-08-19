<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TenantRole;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ComplianceAlertNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

class CheckExceptionSlaCommand extends Command
{
    protected $signature = 'exceptions:check-sla
                            {--tenant= : Limit to a single tenant id}
                            {--dry-run : Report matches without notifying owners}';

    protected $description = 'Alert tenant owners when exception cases are past their SLA due date';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantId = $this->option('tenant');
        $notified = 0;
        $failed = 0;

        $query = Tenant::query()->where('status', 'active')->orderBy('name');

        if (is_string($tenantId) && $tenantId !== '') {
            $query->where('id', $tenantId);
        }

        $query->cursor()->each(function (Tenant $tenant) use ($dryRun, &$notified, &$failed): void {
            try {
                $tenant->run(function () use ($tenant, $dryRun, &$notified): void {
                    $overdue = ExceptionCase::query()->overdue()->orderBy('due_at')->get();

                    if ($overdue->isEmpty()) {
                        return;
                    }

                    $this->line(sprintf(
                        '%s%s: overdue=%d',
                        $dryRun ? '[dry-run] ' : '',
                        $tenant->name,
                        $overdue->count(),
                    ));

                    if ($dryRun) {
                        return;
                    }

                    try {
                        $owners = User::role(TenantRole::Owner->value)->get();
                    } catch (RoleDoesNotExist) {
                        $owners = collect();
                    }

                    if ($owners->isEmpty()) {
                        return;
                    }

                    Notification::send(
                        $owners,
                        new ComplianceAlertNotification(
                            sprintf('%d exception case(s) past SLA', $overdue->count()),
                            $overdue->take(10)->map(
                                fn (ExceptionCase $case): string => $case->caseReference().' — '.$case->title,
                            )->implode("\n"),
                            '/exceptions',
                            (string) $tenant->id,
                            $overdue->count(),
                        ),
                    );

                    $notified++;
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
            'Exception SLA check complete. notified=%d failed=%d%s',
            $notified,
            $failed,
            $dryRun ? ' (dry-run)' : '',
        ));

        return $failed > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }
}
