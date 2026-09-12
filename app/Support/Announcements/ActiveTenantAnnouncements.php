<?php

declare(strict_types=1);

namespace App\Support\Announcements;

use App\Enums\AnnouncementSeverity;
use App\Models\TenantAnnouncement;
use App\Models\User;
use Illuminate\Support\Collection;

final class ActiveTenantAnnouncements
{
    /**
     * @return Collection<int, TenantAnnouncement>
     */
    public function forUser(User $user): Collection
    {
        return TenantAnnouncement::query()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->whereDoesntHave('dismissals', fn ($query) => $query->where('user_id', $user->getKey()))
            ->orderByDesc('published_at')
            ->get()
            ->sort(function (TenantAnnouncement $left, TenantAnnouncement $right): int {
                $severityCompare = $this->severityRank($left->severity) <=> $this->severityRank($right->severity);

                if ($severityCompare !== 0) {
                    return $severityCompare;
                }

                return ($right->published_at?->getTimestamp() ?? 0) <=> ($left->published_at?->getTimestamp() ?? 0);
            })
            ->values();
    }

    private function severityRank(AnnouncementSeverity $severity): int
    {
        return match ($severity) {
            AnnouncementSeverity::Critical => 0,
            AnnouncementSeverity::Warning => 1,
            AnnouncementSeverity::Info => 2,
        };
    }
}
