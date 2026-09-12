<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConnectionFailureStreakAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $connectionName,
        public readonly string $direction,
        public readonly int $consecutiveFailures,
        public readonly ?string $lastError,
        public readonly bool $autoPaused,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->autoPaused
                ? "Connection auto-paused: {$this->connectionName}"
                : "Connection failing: {$this->connectionName}")
            ->line("Your {$this->direction} connection **{$this->connectionName}** has failed {$this->consecutiveFailures} time(s) in a row.");

        if ($this->lastError !== null) {
            $message->line('Last error: '.$this->lastError);
        }

        if ($this->autoPaused) {
            $message->line('The connection was auto-paused to prevent a poison loop. Re-enable it from the connection page once the issue is fixed.');
        } else {
            $message->line('Please review the connection settings and recent documents.');
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->autoPaused ? 'Connection auto-paused' : 'Connection failure streak',
            'connection_name' => $this->connectionName,
            'direction' => $this->direction,
            'consecutive_failures' => $this->consecutiveFailures,
            'last_error' => $this->lastError,
            'auto_paused' => $this->autoPaused,
        ];
    }
}
