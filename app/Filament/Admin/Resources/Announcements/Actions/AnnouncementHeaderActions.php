<?php

namespace App\Filament\Admin\Resources\Announcements\Actions;

use App\Actions\Announcements\PublishAnnouncement;
use App\Actions\Announcements\RetireAnnouncement;
use App\Enums\AnnouncementFanOutStatus;
use App\Enums\AnnouncementStatus;
use App\Jobs\Announcements\FanOutAnnouncementToTenant;
use App\Models\Announcement;
use App\Filament\Notifications\Notification;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

final class AnnouncementHeaderActions
{
    /**
     * @return list<Action>
     */
    public static function make(): array
    {
        return [
            self::publish(),
            self::retire(),
            self::retryFailedFanOut(),
        ];
    }

    private static function publish(): Action
    {
        return Action::make('publish')
            ->label('Publish')
            ->icon(Heroicon::OutlinedMegaphone)
            ->color('success')
            ->visible(fn (Announcement $record): bool => $record->status === AnnouncementStatus::Draft)
            ->requiresConfirmation()
            ->modalHeading('Publish announcement')
            ->modalDescription('Fans out this announcement to selected tenants and notifies active users.')
            ->action(function (Announcement $record, PublishAnnouncement $publishAnnouncement): void {
                try {
                    $publishAnnouncement->handle($record->fresh());
                } catch (InvalidArgumentException $exception) {
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Announcement published')
                    ->success()
                    ->send();
            });
    }

    private static function retire(): Action
    {
        return Action::make('retire')
            ->label('Retire')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('warning')
            ->visible(fn (Announcement $record): bool => $record->status === AnnouncementStatus::Published)
            ->requiresConfirmation()
            ->modalHeading('Retire announcement')
            ->modalDescription('Marks the announcement inactive for all targeted tenants.')
            ->action(function (Announcement $record, RetireAnnouncement $retireAnnouncement): void {
                $retireAnnouncement->handle($record->fresh());

                Notification::make()
                    ->title('Announcement retired')
                    ->success()
                    ->send();
            });
    }

    private static function retryFailedFanOut(): Action
    {
        return Action::make('retryFailedFanOut')
            ->label('Retry failed fan-out')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (Announcement $record): bool => $record->status === AnnouncementStatus::Published
                && $record->tenants()
                    ->wherePivot('fan_out_status', AnnouncementFanOutStatus::Failed->value)
                    ->exists())
            ->requiresConfirmation()
            ->modalHeading('Retry failed fan-out')
            ->modalDescription('Re-dispatches fan-out jobs for tenants whose delivery failed.')
            ->action(function (Announcement $record): void {
                $failedTenants = $record->tenants()
                    ->wherePivot('fan_out_status', AnnouncementFanOutStatus::Failed->value)
                    ->get();

                if ($failedTenants->isEmpty()) {
                    Notification::make()
                        ->title('No failed fan-out rows')
                        ->warning()
                        ->send();

                    return;
                }

                foreach ($failedTenants as $tenant) {
                    $record->tenants()->updateExistingPivot($tenant->getTenantKey(), [
                        'fan_out_status' => AnnouncementFanOutStatus::Pending->value,
                        'fan_out_error' => null,
                    ]);

                    FanOutAnnouncementToTenant::dispatch($record->id, (string) $tenant->getTenantKey());
                }

                Notification::make()
                    ->title('Fan-out retry queued')
                    ->body('Re-dispatched '.$failedTenants->count().' tenant job(s).')
                    ->success()
                    ->send();
            });
    }
}
