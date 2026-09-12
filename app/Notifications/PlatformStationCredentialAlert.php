<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Admin\Pages\PlatformConnections;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells platform operations that TracePharma-owned edge credentials
 * (AS2 station certificates) are expired or expiring.
 */
class PlatformStationCredentialAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $findings
     */
    public function __construct(
        public readonly array $findings,
        public readonly int $warningDays,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(sprintf('%d platform edge credential(s) expired or expiring', count($this->findings)))
            ->line("The following platform-owned credentials need rotation (warning window: {$this->warningDays} days):");

        foreach (array_slice($this->findings, 0, 10) as $finding) {
            $mail->line($finding);
        }

        if (count($this->findings) > 10) {
            $mail->line(sprintf('…and %d more.', count($this->findings) - 10));
        }

        return $mail->action(
            'Open platform connections',
            PlatformConnections::getUrl(panel: 'admin'),
        );
    }
}
