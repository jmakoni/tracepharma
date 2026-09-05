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
use App\Support\Tenancy\TenantRunner;
use Filament\Notifications\DatabaseNotification as FilamentDatabaseNotification;
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

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public string $announcementId,
        public string $tenantId,
    ) {}

    public function handle(): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $announcement = Announcement::query()->findOrFail($this->announcementId);

        $this->updatePivot($announcement->id, (string) $tenant->getTenantKey(), [
            'fan_out_status' => AnnouncementFanOutStatus::Processing->value,
        ]);

        $tenantException = null;

        try {
            TenantRunner::run($tenant, function () use ($announcement): void {
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

                User::query()->orderBy('id')->chunkById(100, function ($users) use ($announcement): void {
                    foreach ($users as $user) {
                        $this->sendAnnouncementBellNotification($user, $announcement);
                    }
                });
            });
        } catch (Throwable $exception) {
            $tenantException = $exception;

            try {
                TenantRunner::run($tenant, function () use ($announcement): void {
                    TenantAnnouncement::query()
                        ->where('announcement_id', $announcement->id)
                        ->update(['is_active' => false]);
                });
            } catch (Throwable) {
                // Best-effort rollback of a partial banner row.
            }
        }

        if ($tenantException !== null) {
            $this->updatePivot($announcement->id, (string) $tenant->getTenantKey(), [
                'fan_out_status' => AnnouncementFanOutStatus::Failed->value,
                'fan_out_error' => $tenantException->getMessage(),
                'fan_out_at' => now(),
            ]);

            $this->fail($tenantException);

            return;
        }

        $this->updatePivot($announcement->id, (string) $tenant->getTenantKey(), [
            'fan_out_status' => AnnouncementFanOutStatus::Succeeded->value,
            'fan_out_error' => null,
            'fan_out_at' => now(),
        ]);
    }

    private function sendAnnouncementBellNotification(User $user, Announcement $announcement): void
    {
        if ($this->userAlreadyNotified($user, $announcement->id)) {
            return;
        }

        $data = Notification::make()
            ->title($announcement->title)
            ->body(str($announcement->body)->stripTags()->limit(200)->toString())
            ->status(match ($announcement->severity) {
                AnnouncementSeverity::Critical => 'danger',
                AnnouncementSeverity::Warning => 'warning',
                default => 'info',
            })
            ->getDatabaseMessage();

        $data['announcement_id'] = $announcement->id;

        $user->notify(new FilamentDatabaseNotification($data));
    }

    private function userAlreadyNotified(User $user, string $announcementId): bool
    {
        return DB::table('notifications')
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->where('data->announcement_id', $announcementId)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updatePivot(string $announcementId, string $tenantId, array $attributes): void
    {
        AnnouncementTenant::on($this->centralConnection())
            ->where('announcement_id', $announcementId)
            ->where('tenant_id', $tenantId)
            ->update($attributes);
    }

    private function centralConnection(): string
    {
        return (string) config('tenancy.database.central_connection', config('database.default'));
    }
}
