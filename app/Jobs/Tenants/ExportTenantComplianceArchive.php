<?php

declare(strict_types=1);

namespace App\Jobs\Tenants;

use App\Models\Tenant;
use App\Services\Tenants\TenantComplianceArchiveGenerator;
use App\Support\TenantSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;

class ExportTenantComplianceArchive implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly int $adminId,
    ) {}

    public function handle(TenantComplianceArchiveGenerator $generator): void
    {
        $result = $this->tenant->run(
            fn (): array => $generator->generate($this->tenant, $this->adminId),
        );

        $timestamp = now()->format('Y-m-d_His');
        $relativePath = $this->tenant->getKey().'/'.$timestamp.'.zip';
        $absolutePath = self::absolutePath($relativePath);

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, $result['binary']);

        $centralTenant = Tenant::query()->findOrFail($this->tenant->getKey());

        TenantSettings::forTenant($centralTenant)->recordComplianceExport(
            $relativePath,
            $this->adminId,
        );
    }

    public static function absolutePath(string $relativePath): string
    {
        return storage_path('app/tenant-exports/'.ltrim($relativePath, '/'));
    }
}
