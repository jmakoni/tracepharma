<?php

declare(strict_types=1);

namespace App\Services\Epcis\Outbound;

use App\Mail\OutboundEpcisAttachmentMail;
use App\Models\Epcis\EpcisDocument;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

final class EmailOutboundSender
{
    public const DEFAULT_MAX_ATTACHMENT_MB = 15;

    public function send(
        OutboundConnection $connection,
        string $content,
        string $filename,
        ?string $contentType = null,
        ?EpcisDocument $document = null,
    ): void {
        $contentType ??= 'application/xml';
        $settings = $connection->settings ?? [];

        $to = $this->normalizeEmails($settings['to_emails'] ?? null);
        if ($to === []) {
            throw new RuntimeException(
                'Email outbound connection is missing settings.to_emails. Add at least one recipient before sending.',
            );
        }

        $maxMb = (int) ($settings['max_attachment_mb'] ?? self::DEFAULT_MAX_ATTACHMENT_MB);
        if ($maxMb < 1) {
            $maxMb = self::DEFAULT_MAX_ATTACHMENT_MB;
        }

        $bytes = strlen($content);
        $maxBytes = $maxMb * 1024 * 1024;
        if ($bytes > $maxBytes) {
            throw new RuntimeException(
                "EPCIS attachment is {$this->formatMb($bytes)} MB which exceeds the {$maxMb} MB email limit. Use Client portal or SFTP instead.",
            );
        }

        $cc = $this->normalizeEmails($settings['cc_emails'] ?? null);
        $subjectTemplate = $settings['subject_template'] ?? null;
        $subject = is_string($subjectTemplate) && $subjectTemplate !== ''
            ? $this->renderSubject($subjectTemplate, $document)
            : null;

        $tenant = tenant();
        $label = $tenant instanceof Tenant
            ? (string) ($tenant->name ?? $tenant->getTenantKey())
            : (string) config('app.name');

        $fromName = $settings['from_name'] ?? null;
        if (is_string($fromName) && $fromName !== '') {
            $label = $fromName;
        }

        $mailable = new OutboundEpcisAttachmentMail(
            partnerOrTenantLabel: $label,
            attachmentFilename: $filename,
            attachmentContent: $content,
            attachmentMime: $contentType,
            asnNumber: $document?->asn_number !== null ? (string) $document->asn_number : null,
            customerPo: $document?->customer_po !== null ? (string) $document->customer_po : null,
            subjectOverride: $subject,
            ccAddresses: $cc,
        );

        Mail::to($to)->send($mailable);
    }

    /**
     * @return list<string>
     */
    private function normalizeEmails(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,;]+/', $raw) ?: [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $emails = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $item = $item['email'] ?? $item['value'] ?? null;
            }
            if (! is_string($item)) {
                continue;
            }
            $email = strtolower(trim($item));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    private function renderSubject(string $template, ?EpcisDocument $document): string
    {
        return str_replace(
            ['{asn}', '{po}', '{filename}'],
            [
                (string) ($document?->asn_number ?? ''),
                (string) ($document?->customer_po ?? ''),
                (string) ($document?->original_filename ?? ''),
            ],
            $template,
        );
    }

    private function formatMb(int $bytes): string
    {
        return number_format($bytes / (1024 * 1024), 2);
    }
}
