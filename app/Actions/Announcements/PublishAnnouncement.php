<?php

declare(strict_types=1);

namespace App\Actions\Announcements;

use App\Enums\AnnouncementFanOutStatus;
use App\Enums\AnnouncementStatus;
use App\Jobs\Announcements\FanOutAnnouncementToTenant;
use App\Models\Announcement;
use InvalidArgumentException;

final class PublishAnnouncement
{
    public function handle(Announcement $announcement): void
    {
        if ($announcement->tenants()->count() < 1) {
            throw new InvalidArgumentException('Select at least one tenant before publishing.');
        }

        if ($announcement->status === AnnouncementStatus::Published) {
            return;
        }

        $announcement->forceFill([
            'status' => AnnouncementStatus::Published,
            'published_at' => now(),
        ])->save();

        foreach ($announcement->tenants()->get() as $tenant) {
            $announcement->tenants()->updateExistingPivot($tenant->getTenantKey(), [
                'fan_out_status' => AnnouncementFanOutStatus::Pending->value,
                'fan_out_error' => null,
            ]);
            FanOutAnnouncementToTenant::dispatch($announcement->id, (string) $tenant->getTenantKey());
        }
    }
}
