<?php

declare(strict_types=1);

namespace App\Jobs\Announcements;

use App\Models\Tenant;
use App\Models\TenantAnnouncement;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RetireAnnouncementOnTenant implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public string $announcementId,
        public string $tenantId,
    ) {}

    public function handle(): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        TenantRunner::run($tenant, function (): void {
            TenantAnnouncement::query()
                ->where('announcement_id', $this->announcementId)
                ->update(['is_active' => false]);
        });
    }
}
