<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\TracingRequest;
use App\Models\User;
use App\Notifications\ComplianceAlertNotification;
use App\Services\Tracing\TracingSlaService;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

class CheckTracingSlaCommand extends Command
{
    protected $signature = 'tracing:check-sla
                            {--tenant= : Limit to a single tenant id}
                            {--dry-run : Report matches without notifying owners}';

    protected $description = 'Flag overdue tracing requests and alert tenant owners when the SLA first breaches';

    public function handle(TracingSlaService $slaService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantId = $this->option('tenant');
        $notified = 0;
        $failed = 0;

        $query = Tenant::query()->where('status', 'active')->orderBy('name');

        if (is_string($tenantId) && $tenantId !== '') {
            $query->where('id', $tenantId);
        }

        $query->cursor()->each(function (Tenant $tenant) use ($slaService, $dryRun, &$notified, &$failed): void {
            try {
                TenantRunner::run($tenant, function () use ($tenant, $slaService, $dryRun, &$notified): void {
                    $overdue = $slaService->findOverdue();

                    if ($overdue->isEmpty()) {
                        return;
                    }

                    $newlyBreached = $overdue->filter(
                        fn (TracingRequest $request): bool => ! $request->sla_breached,
                    );

                    $this->line(sprintf(
                        '%s%s: overdue=%d newly_breached=%d',
                        $dryRun ? '[dry-run] ' : '',
                        $tenant->name,
                        $overdue->count(),
                        $newlyBreached->count(),
                    ));

                    if ($dryRun) {
                        return;
                    }

                    foreach ($overdue as $request) {
                        $slaService->flagBreached($request);
                    }

                    if ($newlyBreached->isEmpty()) {
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
                            sprintf('%d tracing request(s) past SLA', $newlyBreached->count()),
                            $newlyBreached->take(10)->map(
                                fn (TracingRequest $request): string => '#'.$request->getKey().' — '.$request->title,
                            )->implode("\n"),
                            '/tracing-requests',
                            (string) $tenant->id,
                            $newlyBreached->count(),
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
            'Tracing SLA check complete. notified=%d failed=%d%s',
            $notified,
            $failed,
            $dryRun ? ' (dry-run)' : '',
        ));

        return $failed > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }
}
