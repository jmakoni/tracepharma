<?php

declare(strict_types=1);

namespace App\Support\Receiving;

use App\Models\Receiving\ReceivingSession;

/**
 * Draft-only partner email body from open receive exceptions. Does not send.
 */
final class ReceiveExceptionPartnerEmailDraft
{
    public function fromSession(ReceivingSession $session): string
    {
        $refs = ReceivingIssueSessionLabel::orderRefs($session);
        $lines = [
            'Receiving exception draft (not sent)',
            'Receiving #'.$session->getKey(),
        ];

        if ($refs['po'] !== null) {
            $lines[] = 'PO: '.$refs['po'];
        }
        if ($refs['asn'] !== null) {
            $lines[] = 'DESADV: '.$refs['asn'];
        }

        foreach (ReceiveSessionExceptionQuery::openCases($session) as $case) {
            $code = $case->type?->code ?? 'UNKNOWN';
            $activity = $case->activities()->latest('id')->first();
            $meta = is_array($activity?->meta) ? $activity->meta : [];
            $lines[] = '';
            $lines[] = 'Category: '.$code;
            $lines[] = 'NDC/GTIN: '.((string) ($meta['gtin'] ?? '—'));
            $lines[] = 'Lot: '.((string) ($meta['lot'] ?? '—'));
            $lines[] = 'SSCC: '.((string) ($meta['sscc'] ?? '—'));
            $lines[] = 'Scan: '.((string) ($meta['scan_raw'] ?? '—'));
        }

        return implode("\n", $lines);
    }
}
