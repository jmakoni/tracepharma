<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

/**
 * Scrub credentials / inbound_token that may already exist in activity_log for
 * InboundConnection subjects (historical rows written before logExcept).
 */
class RedactInboundConnectionActivitySecretsCommand extends Command
{
    protected $signature = 'activitylog:redact-inbound-connection-secrets {--tenant= : Limit to a single tenant id}';

    protected $description = 'Remove credentials and inbound_token keys from InboundConnection activity_log properties';

    private const SECRET_KEYS = ['credentials', 'inbound_token'];

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $redacted = 0;
        $errors = 0;

        $tenants = $tenantId
            ? Tenant::query()->where('id', $tenantId)->get()
            : Tenant::query()->where('status', 'active')->get();

        foreach ($tenants as $tenant) {
            try {
                TenantRunner::run($tenant, function () use (&$redacted): void {
                    $subjectType = (new InboundConnection)->getMorphClass();

                    Activity::query()
                        ->where('subject_type', $subjectType)
                        ->orderBy('id')
                        ->each(function (Activity $activity) use (&$redacted): void {
                            if ($this->redactActivity($activity)) {
                                $redacted++;
                            }
                        });
                });
            } catch (Throwable $exception) {
                $errors++;
                $this->error("{$tenant->name}: {$exception->getMessage()}");
            } finally {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        }

        $this->info("Redacted {$redacted} InboundConnection activity row(s).");

        return $errors > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }

    private function redactActivity(Activity $activity): bool
    {
        $properties = $activity->properties?->toArray() ?? [];
        $changed = false;

        foreach (['attributes', 'old'] as $section) {
            if (! isset($properties[$section]) || ! is_array($properties[$section])) {
                continue;
            }

            foreach (self::SECRET_KEYS as $key) {
                if (array_key_exists($key, $properties[$section])) {
                    unset($properties[$section][$key]);
                    $changed = true;
                }
            }
        }

        if (! $changed) {
            return false;
        }

        $activity->properties = $properties;
        $activity->save();

        return true;
    }
}
