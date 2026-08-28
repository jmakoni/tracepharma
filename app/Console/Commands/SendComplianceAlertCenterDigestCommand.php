<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TenantRole;
use App\Filament\App\Pages\ComplianceAlertCenter;
use App\Filament\App\Pages\IntegrationHealth;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ComplianceAlertNotification;
use App\Support\Auth\SupportEngineerEmail;
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

    protected $description = 'Email compliance contacts ATP/exception digests and Support Engineers integration failures';

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

                    $metrics = app(ComplianceAlertMetrics::class);
                    $complianceAlerts = $metrics->alertsForAudience(ComplianceAlertMetrics::AUDIENCE_COMPLIANCE);
                    $integrationAlerts = $metrics->alertsForAudience(ComplianceAlertMetrics::AUDIENCE_INTEGRATION);

                    if ($complianceAlerts === [] && $integrationAlerts === []) {
                        $this->line(sprintf('%s%s: no active alerts', $dryRun ? '[dry-run] ' : '', $tenant->name));

                        return;
                    }

                    $sentAny = false;

                    if ($complianceAlerts !== []) {
                        $recipients = $this->complianceRecipientEmails($settings);
                        $sentAny = $this->sendDigest(
                            $tenant,
                            $recipients,
                            $complianceAlerts,
                            sprintf('%d compliance alert(s) need attention', count($complianceAlerts)),
                            '/'.ltrim(ComplianceAlertCenter::getSlug(), '/'),
                            $dryRun,
                            'compliance',
                        ) || $sentAny;
                    }

                    if ($integrationAlerts !== []) {
                        $recipients = $this->integrationRecipientEmails();
                        $sentAny = $this->sendDigest(
                            $tenant,
                            $recipients,
                            $integrationAlerts,
                            sprintf('%d integration alert(s) need attention', count($integrationAlerts)),
                            '/'.ltrim(IntegrationHealth::getSlug(), '/'),
                            $dryRun,
                            'integration',
                        ) || $sentAny;
                    }

                    if ($sentAny && ! $dryRun) {
                        $settings->setAlertDigestLastSentAt(now())->saveQuietly();
                        $notified++;
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
            'Alert center digest complete. notified=%d skipped=%d failed=%d%s',
            $notified,
            $skipped,
            $failed,
            $dryRun ? ' (dry-run)' : '',
        ));

        return $failed > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }

    /**
     * @param  list<array{severity: string, title: string, detail: string, audience?: string, href?: string}>  $alerts
     * @param  list<string>  $recipients
     */
    private function sendDigest(
        Tenant $tenant,
        array $recipients,
        array $alerts,
        string $subject,
        string $actionPath,
        bool $dryRun,
        string $audienceLabel,
    ): bool {
        $this->line(sprintf(
            '%s%s: %s alerts=%d recipients=%d',
            $dryRun ? '[dry-run] ' : '',
            $tenant->name,
            $audienceLabel,
            count($alerts),
            count($recipients),
        ));

        if ($dryRun || $recipients === []) {
            return false;
        }

        $lines = array_map(
            fn (array $alert): string => sprintf(
                '[%s] %s — %s',
                strtoupper((string) $alert['severity']),
                $alert['title'],
                $alert['detail'],
            ),
            $alerts,
        );

        $notification = new ComplianceAlertNotification(
            $subject,
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

        return true;
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
    private function complianceRecipientEmails(TenantSettings $settings): array
    {
        $emails = array_values(array_unique(array_filter([
            $settings->complianceContactEmail(),
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

    /**
     * Support Engineers on the tenant, else TracePharma ops inbox.
     *
     * @return list<string>
     */
    private function integrationRecipientEmails(): array
    {
        try {
            $emails = User::role(TenantRole::SupportEngineer->value)
                ->whereNotNull('email')
                ->pluck('email')
                ->filter(fn (mixed $email): bool => is_string($email) && filled($email))
                ->map(fn (string $email): string => strtolower(trim($email)))
                ->unique()
                ->values()
                ->all();

            if ($emails !== []) {
                return $emails;
            }
        } catch (RoleDoesNotExist) {
            // fall through to ops inbox
        }

        return [SupportEngineerEmail::OPS_INBOX];
    }
}
