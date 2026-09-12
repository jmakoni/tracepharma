<?php

namespace App\Jobs;

use App\Actions\Tenancy\EnsureTenantStorageDirectories;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

class EnsureTenantStorageDirectoriesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        protected TenantWithDatabase $tenant,
    ) {}

    /**
     * @return array{path: string, created: list<string>}
     */
    public function handle(EnsureTenantStorageDirectories $ensure): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->tenant instanceof Tenant
            ? $this->tenant
            : Tenant::query()->findOrFail($this->tenant->getTenantKey());

        return $ensure->handle($tenant);
    }
}
