<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TenantRole;
use App\Models\AtpLicense;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ComplianceAlertNotification;
use App\Support\MasterData\AlertableAtpLicenses;
use App\Support\MasterData\AtpLicenseExpiry;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

class AlertExpiringAtpLicensesCommand extends Command
{
    protected $signature = 'compliance:alert-license-expiry
                            {--tenant= : Limit to a single tenant id}
                            {--dry-run : Report matches without notifying owners}';

    protected $description = 'Email tenant owners about ATP licenses that are expired or expiring within 90 days';

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
                TenantRunner::run($tenant, function () use ($tenant, $dryRun, &$notified): void {
                    $licenses = AlertableAtpLicenses::query();

                    if ($licenses->isEmpty()) {
                        return;
                    }

                    $this->line(sprintf(
                        '%s%s: atp_alerts=%d',
                        $dryRun ? '[dry-run] ' : '',
                        $tenant->name,
                        $licenses->count(),
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

                    $today = AtpLicenseExpiry::today();
                    $lines = $licenses->take(10)->map(function (AtpLicense $license) use ($today): string {
                        $expired = $license->license_expiration_date !== null
                            && $license->license_expiration_date->lt($today);

                        $country = strtoupper(trim((string) ($license->getAttribute('license_country') ?? 'US')));
                        $jurisdiction = $country === 'US'
                            ? (string) $license->license_state
                            : $country.' '.$license->license_state;

                        return sprintf(
                            '%s %s %s — %s (%s)',
                            $expired ? 'Expired' : 'Expiring',
                            $license->license_number,
                            $jurisdiction,
                            $license->license_expiration_date?->toDateString() ?? 'unknown',
                            $license->site?->name ?? 'site '.$license->site_id,
                        );
                    });

                    $firstSiteId = $licenses->first(
                        fn (AtpLicense $license): bool => $license->site_id !== null,
                    )?->site_id;

                    Notification::send(
                        $owners,
                        new ComplianceAlertNotification(
                            sprintf('%d ATP license(s) expired or expiring', $licenses->count()),
                            $lines->implode("\n"),
                            $firstSiteId !== null
                                ? '/sites/'.$firstSiteId.'?relation=1'
                                : '/sites',
                            (string) $tenant->id,
                            $licenses->count(),
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
            'ATP license expiry check complete. notified=%d failed=%d%s',
            $notified,
            $failed,
            $dryRun ? ' (dry-run)' : '',
        ));

        return $failed > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }
}
