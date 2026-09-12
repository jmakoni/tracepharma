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
 * Cardinal-style verification request to the manufacturer when VRS cannot complete.
 */
class ManufacturerVerificationRequestMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly VerificationRequestCase $case,
        public readonly string $caseUrl,
        public readonly string $secureCode,
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
        $requestor = $this->case->requestor_name;
        $gln = filled($this->case->requestor_gln) ? 'GLN # '.$this->case->requestor_gln : null;
        $license = filled($this->case->requestor_license)
            ? 'We are an Authorized Trading Partner (State License '.$this->case->requestor_license.') and have this product in our possession.'
            : 'We are an Authorized Trading Partner and have this product in our possession.';

        $mail = (new MailMessage)
            ->subject('DSCSA verification request — GTIN '.$this->case->gtin14)
            ->greeting('Verification request from '.$requestor)
            ->line('PLEASE NOTE: DSCSA requires manufacturers to respond to verification requests within 24 hours. We will be tracking turnaround time for compliance purposes.')
            ->line('To: '.$requestor)
            ->when(filled($this->case->vendor_number), fn (MailMessage $m) => $m->line('Vendor #: '.$this->case->vendor_number))
            ->line('Transaction Date: '.now()->toFormattedDateString())
            ->line($requestor.' was unable to complete a verification through the Verification Router Service (VRS) for the following product:')
            ->line('NDC: '.($this->case->ndc11 ?? '—'))
            ->when(filled($this->case->cin), fn (MailMessage $m) => $m->line('CIN: '.$this->case->cin))
            ->line('Description: '.($this->case->product_description ?? '—'))
            ->line('GTIN: '.$this->formatGtin($this->case->gtin14))
            ->line('Serial Number: '.$this->case->serial)
            ->line('Lot Number: '.($this->case->lot ?? '—'))
            ->line('Expiration Date: '.($this->case->expiry_yymmdd ?? '—'))
            ->when(filled($this->case->notes), fn (MailMessage $m) => $m->line('Notes: '.$this->case->notes))
            ->line($license)
            ->line('Please use the following link and secure code to access the case and complete your response.')
            ->line('Link: '.$this->caseUrl)
            ->line('Secure Code: '.$this->secureCode)
            ->line('This code is valid until '.$this->case->expires_at?->toFormattedDateString().'.')
            ->action('Open verification case', $this->caseUrl);

        if ($gln !== null) {
            $mail->line($gln);
        }

        $contact = TenantSettings::forTenant(tenant())->vrsVerificationContactEmail();
        if (filled($contact)) {
            $mail->line('If you have any issues accessing the link, please email us at '.$contact);
        }

        return $mail;
    }

    private function formatGtin(string $gtin14): string
    {
        return str_pad(preg_replace('/\D+/', '', $gtin14) ?? '', 14, '0', STR_PAD_LEFT);
    }
}
