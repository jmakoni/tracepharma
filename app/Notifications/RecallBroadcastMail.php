<?php

namespace App\Notifications;

use App\Models\TracingRequest;
use App\Models\TradingPartner;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RecallBroadcastMail extends Notification
{
    public function __construct(
        public readonly TracingRequest $request,
        public readonly TradingPartner $partner,
        public readonly string $ackUrl,
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
        $request = $this->request;
        $tenantName = (string) (tenant('name') ?? config('app.name'));

        $mail = (new MailMessage)
            ->from(
                config('tracepharma.exception_mail.from_address'),
                config('tracepharma.exception_mail.from_name'),
            )
            ->subject('Product recall notice | '.$request->title)
            ->greeting('Recall notice')
            ->line('**From:** '.$tenantName)
            ->line('**Recipient:** '.($this->partner->name ?: 'Trading partner'))
            ->line('**Recall:** '.$request->title);

        if (filled($request->gtin)) {
            $mail->line('**GTIN:** '.$request->gtin);
        }

        if (filled($request->lot)) {
            $mail->line('**Lot:** '.$request->lot);
        }

        if ($request->expiry !== null) {
            $mail->line('**Expiry:** '.$request->expiry->toFormattedDateString());
        }

        if (filled($request->notes)) {
            $mail->line('**Notes:** '.$request->notes);
        }

        return $mail
            ->action('Acknowledge receipt', $this->ackUrl)
            ->line('Use the button above to confirm your organization received this recall notice. No login is required.')
            ->salutation('TracePharma Recall Notifications');
    }
}
