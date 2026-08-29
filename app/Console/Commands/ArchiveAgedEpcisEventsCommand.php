<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Epcis\ArchiveAgedEpcisEvents;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

class ArchiveAgedEpcisEventsCommand extends Command
{
    protected $signature = 'tracepharma:epcis-archive-events
        {--tenant= : Limit to a single tenant id}
        {--dry-run : Report counts without moving events}';

    protected $description = 'MOVE epcis_events older than retention_years into archive tables';

    public function handle(ArchiveAgedEpcisEvents $archive): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantId = $this->option('tenant');
        $tenantFailures = 0;

        $query = Tenant::query()->where('status', 'active')->orderBy('name');

        if (is_string($tenantId) && $tenantId !== '') {
            $query->where('id', $tenantId);
        }

        $query->cursor()->each(function (Tenant $tenant) use ($archive, $dryRun, &$tenantFailures): void {
            try {
                $tenant->run(function () use ($tenant, $archive, $dryRun): void {
                    $result = $archive->handle($dryRun);

                    $this->info(sprintf(
                        '%s: would_archive=%d archived=%d%s',
                        $tenant->name,
                        $result['would_archive'],
                        $result['archived'],
                        $dryRun ? ' (dry-run)' : '',
                    ));
                });
            } catch (Throwable $exception) {
                $tenantFailures++;
                $this->error("{$tenant->name}: {$exception->getMessage()}");
            }
        });

        return $tenantFailures > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }
}
