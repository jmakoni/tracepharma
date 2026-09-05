<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class OutboundEpcisAttachmentMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<string>  $ccAddresses
     */
    public function __construct(
        public readonly string $partnerOrTenantLabel,
        public readonly string $attachmentFilename,
        public readonly string $attachmentContent,
        public readonly string $attachmentMime,
        public readonly ?string $asnNumber = null,
        public readonly ?string $customerPo = null,
        public readonly ?string $subjectOverride = null,
        public readonly array $ccAddresses = [],
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->subjectOverride;
        if (! is_string($subject) || $subject === '') {
            $parts = ['EPCIS transaction information'];
            if (filled($this->asnNumber)) {
                $parts[] = 'ASN '.$this->asnNumber;
            }
            if (filled($this->customerPo)) {
                $parts[] = 'PO '.$this->customerPo;
            }
            $subject = implode(' — ', $parts);
        }

        return new Envelope(
            subject: $subject,
            cc: $this->ccAddresses,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.outbound-epcis-attachment',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn (): string => $this->attachmentContent,
                $this->attachmentFilename,
            )->withMime($this->attachmentMime),
        ];
    }
}
