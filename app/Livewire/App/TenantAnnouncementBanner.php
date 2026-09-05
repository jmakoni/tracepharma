<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Models\TenantAnnouncement;
use App\Models\TenantAnnouncementDismissal;
use App\Support\Announcements\ActiveTenantAnnouncements;
use Illuminate\Support\Collection;
use Livewire\Component;

class TenantAnnouncementBanner extends Component
{
    /** @var Collection<int, TenantAnnouncement> */
    public Collection $announcements;

    public function mount(ActiveTenantAnnouncements $activeTenantAnnouncements): void
    {
        $this->announcements = $this->resolveAnnouncements($activeTenantAnnouncements);
    }

    public function dismiss(int $tenantAnnouncementId, ActiveTenantAnnouncements $activeTenantAnnouncements): void
    {
        if (! $this->shouldRender()) {
            return;
        }

        $user = auth()->user();

        if ($user === null) {
            return;
        }

        TenantAnnouncementDismissal::query()->firstOrCreate(
            [
                'tenant_announcement_id' => $tenantAnnouncementId,
                'user_id' => $user->getKey(),
            ],
            [
                'dismissed_at' => now(),
            ],
        );

        $this->announcements = $this->resolveAnnouncements($activeTenantAnnouncements);
    }

    public function render(): mixed
    {
        if (! $this->shouldRender()) {
            return view('livewire.app.tenant-announcement-banner', [
                'announcements' => collect(),
            ]);
        }

        return view('livewire.app.tenant-announcement-banner');
    }

    /**
     * @return Collection<int, TenantAnnouncement>
     */
    private function resolveAnnouncements(ActiveTenantAnnouncements $activeTenantAnnouncements): Collection
    {
        if (! $this->shouldRender()) {
            return collect();
        }

        $user = auth()->user();

        if ($user === null) {
            return collect();
        }

        return $activeTenantAnnouncements->forUser($user);
    }

    private function shouldRender(): bool
    {
        return tenancy()->initialized && filament()->getId() === 'app';
    }
}
