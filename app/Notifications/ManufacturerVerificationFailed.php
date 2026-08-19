<?php

namespace App\Notifications;

use App\Models\Exceptions\ExceptionCase;
use App\Models\Verification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ManufacturerVerificationFailed extends Notification implements ShouldQueue
{
    use Queueable;

    /** Queued mail for manufacturer notification after a failed/suspect outbound verify. */
    public function __construct(
        public readonly Verification $verification,
        public readonly ExceptionCase $exception,
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
        return (new MailMessage)
            ->subject('DSCSA verification failure notification — GTIN '.$this->verification->gtin14)
            ->greeting('Verification failure notice')
            ->line('A dispenser attempted to verify a serialized product and received a negative or inconclusive VRS result.')
            ->line('GTIN: '.$this->verification->gtin14)
            ->line('Serial: '.($this->verification->serial ?? '—'))
            ->line('Lot: '.($this->verification->lot ?? '—'))
            ->line('Facility: '.(tenant('name') ?? 'Dispenser').' (GLN '.(tenant('gln') ?? '—').')')
            ->line('Outcome: '.($this->exception->description ?? 'Verification failed'))
            ->line('Case: '.$this->exception->caseReference());
    }
}
