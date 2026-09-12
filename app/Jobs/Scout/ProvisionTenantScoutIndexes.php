<?php

declare(strict_types=1);

namespace App\Jobs\Scout;

use App\Support\Scout\TenantScoutCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class ProvisionTenantScoutIndexes implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(
        public readonly string $tenantId,
    ) {}

    public function handle(): void
    {
        if (! TenantScoutCatalog::usesMeilisearch()) {
            return;
        }

        $exit = Artisan::call('tracepharma:scout-sync-index-settings', [
            '--tenant' => $this->tenantId,
        ]);

        if ($exit !== SymfonyCommand::SUCCESS) {
            throw new \RuntimeException(
                "Scout index settings sync failed for tenant {$this->tenantId} (exit code {$exit}).",
            );
        }
    }

    public function failed(?\Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        report($exception);
    }
}
