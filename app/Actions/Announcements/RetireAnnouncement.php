<?php

declare(strict_types=1);

namespace App\Actions\Announcements;

use App\Enums\AnnouncementStatus;
use App\Jobs\Announcements\RetireAnnouncementOnTenant;
use App\Models\Announcement;

final class RetireAnnouncement
{
    public function handle(Announcement $announcement): void
    {
        $announcement->forceFill([
            'status' => AnnouncementStatus::Retired,
            'retired_at' => now(),
        ])->save();

        foreach ($announcement->tenants()->get() as $tenant) {
            RetireAnnouncementOnTenant::dispatch($announcement->id, (string) $tenant->getTenantKey());
        }
    }
}
