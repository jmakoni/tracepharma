<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TenantRole;
use App\Filament\App\Pages\ComplianceAlertCenter;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ComplianceAlertNotification;
use App\Support\Compliance\ComplianceAlertMetrics;
use App\Support\TenantSettings;
use Illuminate\Console\Command;
use Illuminate\Notifications\AnonymousNotifiable;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

class SendComplianceAlertCenterDigestCommand extends Command
{
    protected $signature = 'compliance:alert-center-digest
                            {--tenant= : Limit to a single tenant id}
                            {--dry-run : Report matches without sending mail}
                            {--force : Ignore frequency (daily/weekly) gate}';

    protected $description = 'Email compliance/IT contacts a digest of Compliance Alert Center signals';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $tenantId = $this->option('tenant');
        $notified = 0;
        $skipped = 0;
        $failed = 0;

        $query = Tenant::query()->where('status', 'active')->orderBy('name');

        if (is_string($tenantId) && $tenantId !== '') {
            $query->where('id', $tenantId);
        }

        $query->cursor()->each(function (Tenant $tenant) use (
            $dryRun,
            $force,
            &$notified,
            &$skipped,
            &$failed,
        ): void {
            try {
                $tenant->run(function () use ($tenant, $dryRun, $force, &$notified, &$skipped): void {
                    $settings = TenantSettings::forTenant($tenant);

                    if (! $settings->alertDigestEnabled()) {
                        $skipped++;

                        return;
                    }

                    if (! $force && ! $this->shouldRunForFrequency($settings->alertDigestFrequency())) {
                        $skipped++;

                        return;
                    }

                    $alerts = app(ComplianceAlertMetrics::class)->alerts(null);

                    if ($alerts === []) {
                        $this->line(sprintf('%s%s: no active alerts', $dryRun ? '[dry-run] ' : '', $tenant->name));

                        return;
                    }

                    $recipients = $this->recipientEmails($settings);
                    $lines = array_map(
                        fn (array $alert): string => sprintf(
                            '[%s] %s — %s',
                            strtoupper((string) $alert['severity']),
                            $alert['title'],
                            $alert['detail'],
                        ),
                        $alerts,
                    );

                    $this->line(sprintf(
                        '%s%s: alerts=%d recipients=%d',
                        $dryRun ? '[dry-run] ' : '',
                        $tenant->name,
                        count($alerts),
                        count($recipients),
                    ));

                    if ($dryRun || $recipients === []) {
                        return;
                    }

                    $actionPath = '/'.ltrim(ComplianceAlertCenter::getSlug(), '/');
                    $notification = new ComplianceAlertNotification(
                        sprintf('%d compliance alert(s) need attention', count($alerts)),
                        implode("\n", $lines),
                        $actionPath,
                        (string) $tenant->id,
                        count($alerts),
                    );

                    foreach ($recipients as $email) {
                        (new AnonymousNotifiable)
                            ->route('mail', $email)
                            ->notify($notification);
                    }

                    $settings->setAlertDigestLastSentAt(now())->saveQuietly();
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
            'Alert center digest complete. notified=%d skipped=%d failed=%d%s',
            $notified,
            $skipped,
            $failed,
            $dryRun ? ' (dry-run)' : '',
        ));

        return $failed > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }

    private function shouldRunForFrequency(string $frequency): bool
    {
        if ($frequency === 'weekly') {
            return now()->isMonday();
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function recipientEmails(TenantSettings $settings): array
    {
        $emails = array_values(array_unique(array_filter([
            $settings->complianceContactEmail(),
            $settings->itContactEmail(),
        ])));

        if ($emails !== []) {
            return $emails;
        }

        try {
            return User::role(TenantRole::Owner->value)
                ->whereNotNull('email')
                ->pluck('email')
                ->filter(fn (mixed $email): bool => is_string($email) && filled($email))
                ->unique()
                ->values()
                ->all();
        } catch (RoleDoesNotExist) {
            return [];
        }
    }
}
