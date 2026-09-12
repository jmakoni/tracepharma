<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DataExportStatus;
use App\Models\DataExport;
use App\Models\Tenant;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

class FailStaleDataExports extends Command
{
    protected $signature = 'exports:fail-stale {--tenant=}';

    protected $description = 'Mark data exports stuck in processing as failed';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $failed = 0;
        $errors = 0;
        $staleHours = max(1, (int) config('tracepharma.exports.stale_processing_hours', 2));
        $cutoff = now()->subHours($staleHours);

        $tenants = $tenantId
            ? Tenant::query()->where('id', $tenantId)->get()
            : Tenant::query()->where('status', 'active')->get();

        foreach ($tenants as $tenant) {
            try {
                TenantRunner::run($tenant, function () use ($cutoff, &$failed): void {
                    DataExport::query()
                        ->where('status', DataExportStatus::Processing)
                        ->where(function ($query) use ($cutoff): void {
                            $query->where('started_at', '<', $cutoff)
                                ->orWhere(function ($nested) use ($cutoff): void {
                                    $nested->whereNull('started_at')
                                        ->where('updated_at', '<', $cutoff);
                                });
                        })
                        ->orderBy('id')
                        ->each(function (DataExport $export) use (&$failed): void {
                            $export->markFailed('Export timed out while processing.');
                            $failed++;
                        });
                });
            } catch (Throwable $exception) {
                $errors++;
                $this->error("{$tenant->name}: {$exception->getMessage()}");
            }
        }

        $this->info("Marked {$failed} stale export(s) as failed.");

        return $errors > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }
}
