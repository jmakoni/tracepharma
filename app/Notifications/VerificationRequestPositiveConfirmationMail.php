<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\VerificationRequestCase;
use App\Support\TenantSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Cardinal-style confirmation to the requestor when manufacturer submits positive verification.
 */
class VerificationRequestPositiveConfirmationMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly VerificationRequestCase $case,
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
        $settings = TenantSettings::forTenant(tenant());
        $requestor = $this->case->requestor_name;
        $address = $settings->organizationAddress();
        $cityStateZip = trim(implode(' ', array_filter([
            $address['city'] ?? null,
            $address['state'] ?? null,
            $address['zipcode'] ?? null,
        ])));

        $mail = (new MailMessage)
            ->subject('Positive manufacturer verification — GTIN '.$this->case->gtin14)
            ->greeting('From: '.$requestor);

        foreach (array_filter([
            $address['street_address'] ?? null,
            $address['street_address_2'] ?? null,
            $cityStateZip !== '' ? $cityStateZip : null,
        ]) as $line) {
            $mail->line($line);
        }

        if (filled($this->case->requestor_gln)) {
            $mail->line('GLN # '.$this->case->requestor_gln);
        }

        $mail->line('To: '.$this->case->requestor_notify_email.' Transaction Date: '.($this->case->responded_at ?? now())->format('n/j/Y'));

        if (filled($this->case->vendor_number)) {
            $mail->line('Vendor #: '.$this->case->vendor_number);
        }

        $mail->line('')
            ->line($requestor.' has received a positive verification for the following product through the TracePharma DSCSA Exceptions Portal.')
            ->line('NDC: '.($this->case->ndc11 ?? '—'));

        if (filled($this->case->cin)) {
            $mail->line('CIN: '.$this->case->cin);
        }

        $mail->line('Description: '.($this->case->product_description ?? '—'))
            ->line('GTIN: '.$this->formatGtin($this->case->gtin14))
            ->line('Serial Number: '.$this->case->serial)
            ->line('Lot Number: '.($this->case->lot ?? '—'))
            ->line('Expiration Date: '.($this->case->expiry_yymmdd ?? '—'));

        if (filled($this->case->notes)) {
            $mail->line('Notes: '.$this->case->notes);
        }

        $contact = $settings->vrsVerificationContactEmail();
        if (filled($contact)) {
            $mail->line('If this response was not provided by you, please contact '.$contact);
        }

        return $mail;
    }

    private function formatGtin(string $gtin14): string
    {
        return str_pad(preg_replace('/\D+/', '', $gtin14) ?? '', 14, '0', STR_PAD_LEFT);
    }
}
