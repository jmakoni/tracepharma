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

class PurgeExpiredDataExports extends Command
{
    protected $signature = 'exports:purge-expired {--tenant=}';

    protected $description = 'Delete expired track-and-trace export files and database rows';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $purged = 0;
        $errors = 0;

        $tenants = $tenantId
            ? Tenant::query()->where('id', $tenantId)->get()
            : Tenant::query()->where('status', 'active')->get();

        foreach ($tenants as $tenant) {
            try {
                TenantRunner::run($tenant, function () use (&$purged): void {
                    DataExport::query()
                        ->where(function ($query): void {
                            $query->where(function ($completed): void {
                                $completed->where('status', DataExportStatus::Completed)
                                    ->whereNotNull('expires_at')
                                    ->where('expires_at', '<', now());
                            })->orWhere(function ($failed): void {
                                $failed->where('status', DataExportStatus::Failed)
                                    ->where(function ($nested): void {
                                        $nested->whereNotNull('storage_path')
                                            ->orWhereNotNull('storage_disk');
                                    });
                            });
                        })
                        ->orderBy('expires_at')
                        ->each(function (DataExport $export) use (&$purged): void {
                            $export->purgeStorage();
                            $export->delete();
                            $purged++;
                        });
                });
            } catch (Throwable $exception) {
                $errors++;
                $this->error("{$tenant->name}: {$exception->getMessage()}");
            }
        }

        $this->info("Purged {$purged} expired export(s).");

        return $errors > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }
}
