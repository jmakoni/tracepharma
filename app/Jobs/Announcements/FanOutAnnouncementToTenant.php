<?php

declare(strict_types=1);

namespace App\Jobs\Announcements;

use App\Enums\AnnouncementFanOutStatus;
use App\Enums\AnnouncementSeverity;
use App\Filament\Notifications\Notification;
use App\Models\Announcement;
use App\Models\AnnouncementTenant;
use App\Models\Tenant;
use App\Models\TenantAnnouncement;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class FanOutAnnouncementToTenant implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $announcementId,
        public string $tenantId,
    ) {}

    public function handle(): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $announcement = Announcement::query()->findOrFail($this->announcementId);

        AnnouncementTenant::query()
            ->where('announcement_id', $announcement->id)
            ->where('tenant_id', $tenant->getTenantKey())
            ->update(['fan_out_status' => AnnouncementFanOutStatus::Processing->value]);

        try {
            $tenant->run(function () use ($announcement): void {
                TenantAnnouncement::query()->updateOrCreate(
                    ['announcement_id' => $announcement->id],
                    [
                        'title' => $announcement->title,
                        'body' => $announcement->body,
                        'severity' => $announcement->severity,
                        'published_at' => $announcement->published_at,
                        'starts_at' => $announcement->starts_at,
                        'ends_at' => $announcement->ends_at,
                        'is_active' => true,
                    ],
                );

                foreach (User::query()->get() as $user) {
                    if ($this->userAlreadyNotified($user, $announcement->id)) {
                        continue;
                    }

                    Notification::make()
                        ->title($announcement->title)
                        ->body(str($announcement->body)->stripTags()->limit(200)->toString())
                        ->status(match ($announcement->severity) {
                            AnnouncementSeverity::Critical => 'danger',
                            AnnouncementSeverity::Warning => 'warning',
                            default => 'info',
                        })
                        ->sendToDatabase($user);

                    $this->mergeAnnouncementIdIntoLatestNotification($user, $announcement->id);
                }
            });

            AnnouncementTenant::query()
                ->where('announcement_id', $announcement->id)
                ->where('tenant_id', $tenant->getTenantKey())
                ->update([
                    'fan_out_status' => AnnouncementFanOutStatus::Succeeded->value,
                    'fan_out_error' => null,
                    'fan_out_at' => now(),
                ]);
        } catch (Throwable $exception) {
            AnnouncementTenant::query()
                ->where('announcement_id', $announcement->id)
                ->where('tenant_id', $tenant->getTenantKey())
                ->update([
                    'fan_out_status' => AnnouncementFanOutStatus::Failed->value,
                    'fan_out_error' => $exception->getMessage(),
                    'fan_out_at' => now(),
                ]);

            $this->fail($exception);
        }
    }

    private function userAlreadyNotified(User $user, string $announcementId): bool
    {
        return DB::table('notifications')
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->where('data->announcement_id', $announcementId)
            ->exists();
    }

    private function mergeAnnouncementIdIntoLatestNotification(User $user, string $announcementId): void
    {
        $notification = DB::table('notifications')
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->latest('created_at')
            ->first();

        if ($notification === null) {
            return;
        }

        $data = json_decode((string) $notification->data, true);
        $data['announcement_id'] = $announcementId;
        $data['format'] = 'filament';

        DB::table('notifications')
            ->where('id', $notification->id)
            ->update(['data' => json_encode($data)]);
    }
}
