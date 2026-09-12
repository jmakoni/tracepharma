<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Buyer-facing notice after outbound TI is authored — signed portal link, no login account.
 */
class CustomerPortalShipNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $partnerName,
        public readonly string $portalUrl,
        public readonly ?string $asnNumber = null,
        public readonly ?string $customerPo = null,
        public readonly ?string $tenantId = null,
        public readonly ?string $tenantName = null,
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
        $from = $this->tenantName ?? 'your trading partner';

        $mail = (new MailMessage)
            ->subject('EPCIS / TI available for download')
            ->greeting('Hello '.$this->partnerName.',')
            ->line("{$from} has shipped transaction information you can download from the customer portal.")
            ->line('No account is required — use the signed link below (it expires).');

        if (filled($this->asnNumber)) {
            $mail->line('ASN: '.$this->asnNumber);
        }

        if (filled($this->customerPo)) {
            $mail->line('PO: '.$this->customerPo);
        }

        return $mail
            ->action('Open customer portal', $this->portalUrl)
            ->line('If the link expires, ask '.$from.' for a fresh portal link.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'partner_name' => $this->partnerName,
            'portal_url' => $this->portalUrl,
            'asn_number' => $this->asnNumber,
            'customer_po' => $this->customerPo,
        ];
    }
}
