<?php

namespace App\Notifications;

use App\Models\Exceptions\ExceptionCase;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantAppUrl;
use App\Support\TenantNotificationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExceptionUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    private array $channels;

    public function __construct(
        public readonly ExceptionCase $exception,
        public readonly string $action,
        public readonly User $actor,
    ) {
        $this->channels = TenantNotificationSettings::forTenant(
            tenancy()->initialized ? tenant() : Tenant::query()->find(tenant('id')),
        )['channels'];
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels !== [] ? $this->channels : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Exception updated: '.$this->exception->title)
            ->line("Exception was {$this->action} by {$this->actor->name}.")
            ->action('View Exception', $this->reviewUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'exception_id' => $this->exception->id,
            'title' => $this->exception->title,
            'action' => $this->action,
            'actor' => $this->actor->name,
        ];
    }

    private function reviewUrl(): string
    {
        return TenantAppUrl::exception((int) $this->exception->id);
    }
}
