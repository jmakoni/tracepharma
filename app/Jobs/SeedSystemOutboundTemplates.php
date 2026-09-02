<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Outbound\EnsureSystemOutboundTemplates;
use App\Models\Tenant;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

class SeedSystemOutboundTemplates implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        protected TenantWithDatabase $tenant,
    ) {}

    public function handle(EnsureSystemOutboundTemplates $ensure): void
    {
        /** @var Tenant $tenant */
        $tenant = $this->tenant;

        TenantRunner::run($tenant, function () use ($ensure): void {
            $ensure->handle();
        });
    }
}
