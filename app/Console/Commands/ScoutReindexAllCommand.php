<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Scout\TenantScoutCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class ScoutReindexAllCommand extends Command
{
    protected $signature = 'tracepharma:scout-reindex-all
        {--flush : Flush each index before importing}
        {--model=all : products, partners, documents, events, or all}
        {--sync-settings : Sync index settings before reindexing each tenant}';

    protected $description = 'Reindex Scout for every tenant (optional settings sync first)';

    public function handle(): int
    {
        $model = (string) $this->option('model');

        if (TenantScoutCatalog::resolveModels($model) === []) {
            $this->error('Unknown --model value. Use products, partners, documents, events, or all.');

            return SymfonyCommand::FAILURE;
        }

        $syncSettings = (bool) $this->option('sync-settings');
        $failures = 0;
        $successes = 0;
        $total = 0;

        foreach (Tenant::query()->orderBy('id')->cursor() as $tenant) {
            $total++;
            $this->info("Tenant: {$tenant->id} ({$tenant->name})");

            if ($syncSettings && TenantScoutCatalog::usesRemoteIndexSettings()) {
                $settingsExit = Artisan::call('tracepharma:scout-sync-index-settings', [
                    '--tenant' => $tenant->getKey(),
                ]);

                $this->output->write(Artisan::output());

                if ($settingsExit !== SymfonyCommand::SUCCESS) {
                    $failures++;
                    $this->error('  Settings sync failed; skipping reindex for this tenant.');

                    continue;
                }
            }

            $reindexExit = Artisan::call('tracepharma:scout-reindex', [
                '--tenant' => $tenant->getKey(),
                '--model' => $model,
                '--flush' => (bool) $this->option('flush'),
            ]);

            $this->output->write(Artisan::output());

            if ($reindexExit !== SymfonyCommand::SUCCESS) {
                $failures++;

                continue;
            }

            $successes++;
        }

        $this->newLine();
        $this->info("Scout reindex-all complete: {$successes}/{$total} tenant(s) succeeded.");

        if ($failures > 0) {
            $this->error("{$failures} tenant(s) failed.");

            return SymfonyCommand::FAILURE;
        }

        return SymfonyCommand::SUCCESS;
    }
}
