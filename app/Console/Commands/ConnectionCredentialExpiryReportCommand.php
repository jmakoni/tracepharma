<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TenantRole;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ComplianceAlertNotification;
use App\Notifications\PlatformStationCredentialAlert;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Integrations\CredentialExpiry;
use App\Support\Integrations\PlatformAs2Station;
use App\Support\Tenancy\TenantRunner;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

class ConnectionCredentialExpiryReportCommand extends Command
{
    protected $signature = 'connections:credential-expiry-report
                            {--days=30 : Warn about credentials expiring within this many days}
                            {--tenant= : Limit to a single tenant id}
                            {--dry-run : Report matches without notifying}';

    protected $description = 'Alert tenant owners and platform support about connection credentials and AS2 certificates that are expired or expiring soon.';

    public function handle(PlatformAs2Station $as2Station): int
    {
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $tenantId = $this->option('tenant');
        $notified = 0;
        $failed = 0;

        $query = Tenant::query()->where('status', 'active')->orderBy('name');

        if (is_string($tenantId) && $tenantId !== '') {
            $query->where('id', $tenantId);
        }

        $query->cursor()->each(function (Tenant $tenant) use ($days, $dryRun, &$notified, &$failed): void {
            try {
                TenantRunner::run($tenant, function () use ($tenant, $days, $dryRun, &$notified): void {
                    $lines = $this->expiringConnectionLines($days);

                    if ($lines === []) {
                        return;
                    }

                    $this->line(sprintf('%s%s: %d connection(s)', $dryRun ? '[dry-run] ' : '', $tenant->name, count($lines)));

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

                    Notification::send($owners, new ComplianceAlertNotification(
                        sprintf('%d connection credential(s) expired or expiring', count($lines)),
                        implode("\n", $lines),
                        '/inbound-connections',
                        (string) $tenant->id,
                        count($lines),
                    ));

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

        $stationLines = $this->expiringStationLines($as2Station, $days);

        foreach ($stationLines as $line) {
            $this->line('[platform] '.$line);
        }

        if (! $dryRun && $stationLines !== []) {
            $supportEmail = config('tracepharma.platform_support_email');

            if (is_string($supportEmail) && $supportEmail !== '') {
                Notification::route('mail', $supportEmail)
                    ->notify(new PlatformStationCredentialAlert($stationLines, $days));
            }
        }

        $this->info(sprintf(
            'Credential expiry report complete. tenants_notified=%d failed=%d platform_findings=%d%s',
            $notified,
            $failed,
            count($stationLines),
            $dryRun ? ' (dry-run)' : '',
        ));

        return $failed > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function expiringConnectionLines(int $days): array
    {
        $cutoff = now()->addDays($days);
        $lines = [];

        $collect = function ($query, string $direction) use (&$lines): void {
            $query->get()->each(function ($connection) use (&$lines, $direction): void {
                $expires = $connection->credentials_expire_at;

                if (! $expires instanceof CarbonInterface) {
                    return;
                }

                $state = CredentialExpiry::state($expires);

                if (! in_array($state, [CredentialExpiry::STATE_EXPIRED, CredentialExpiry::STATE_EXPIRING], true)) {
                    return;
                }

                $lines[] = sprintf(
                    '%s %s connection "%s" — credentials %s (%s)',
                    $state === CredentialExpiry::STATE_EXPIRED ? 'Expired:' : 'Expiring:',
                    $direction,
                    $connection->name,
                    $expires->toDateString(),
                    $connection->transport?->value ?? 'unknown transport',
                );
            });
        };

        $collect(
            InboundConnection::query()
                ->whereNotNull('credentials_expire_at')
                ->where('credentials_expire_at', '<=', $cutoff),
            'inbound',
        );

        $collect(
            OutboundConnection::query()
                ->whereNotNull('credentials_expire_at')
                ->where('credentials_expire_at', '<=', $cutoff),
            'outbound',
        );

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function expiringStationLines(PlatformAs2Station $station, int $days): array
    {
        $lines = [];

        foreach (EpcisHubPlatformConfig::ENVIRONMENTS as $environment) {
            foreach (['signing' => $station->signingCertPem($environment), 'decrypt' => $station->decryptCertPem($environment)] as $kind => $pem) {
                $expires = $this->parseCertExpiry($pem);

                if ($expires === null) {
                    continue;
                }

                $state = CredentialExpiry::state($expires, $days);

                if (! in_array($state, [CredentialExpiry::STATE_EXPIRED, CredentialExpiry::STATE_EXPIRING], true)) {
                    continue;
                }

                $lines[] = sprintf(
                    '%s AS2 station %s certificate [%s] — %s',
                    $state === CredentialExpiry::STATE_EXPIRED ? 'Expired:' : 'Expiring:',
                    $kind,
                    $environment,
                    $expires->toDateString(),
                );
            }
        }

        return $lines;
    }

    private function parseCertExpiry(?string $pem): ?Carbon
    {
        if (! is_string($pem) || trim($pem) === '') {
            return null;
        }

        $parsed = @openssl_x509_parse($pem);

        if (! is_array($parsed) || ! is_numeric($parsed['validTo_time_t'] ?? null)) {
            return null;
        }

        return Carbon::createFromTimestampUTC((int) $parsed['validTo_time_t']);
    }
}
