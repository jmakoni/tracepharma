<?php

namespace App\Services\Epcis\Inbound;

use App\Enums\As2MdnAckMode;
use App\Models\InboundConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class As2InboundMdnFactory
{
    public function ackMode(InboundConnection $connection): As2MdnAckMode
    {
        $settings = $connection->settings ?? [];

        return As2MdnAckMode::tryFrom((string) ($settings['as2_mdn_ack_mode'] ?? As2MdnAckMode::Sync->value))
            ?? As2MdnAckMode::Sync;
    }

    public function wantsSyncMdn(InboundConnection $connection): bool
    {
        return $this->ackMode($connection) === As2MdnAckMode::Sync;
    }

    public function response(
        Request $request,
        InboundConnection $connection,
        bool $processed,
        ?string $error = null,
    ): Response {
        $messageId = $request->header('Message-ID') ?: $request->header('Message-Id');
        $original = is_string($messageId) && $messageId !== '' ? $messageId : '<unknown@tracepharma>';
        $boundary = 'tp-mdn-'.Str::lower((string) Str::uuid());
        $disposition = $processed
            ? 'automatic-action/MDN-sent-automatically; processed'
            : 'automatic-action/MDN-sent-automatically; failed/failure: unexpected-processing-error';
        $human = $processed
            ? 'The AS2 message was processed.'
            : 'The AS2 message could not be processed.';

        $body = implode("\r\n", [
            '--'.$boundary,
            'Content-Type: text/plain',
            '',
            $human,
            '--'.$boundary,
            'Content-Type: message/disposition-notification',
            '',
            'Reporting-UA: TracePharma',
            'Original-Message-ID: '.$original,
            'Disposition: '.$disposition,
            '--'.$boundary.'--',
            '',
        ]);

        return response($body, $processed ? 200 : 200)
            ->header('Content-Type', 'multipart/report; report-type=disposition-notification; boundary="'.$boundary.'"')
            ->header('AS2-From', (string) ($connection->settings['as2_to'] ?? ''))
            ->header('AS2-To', (string) ($connection->settings['as2_from'] ?? ''))
            ->header('Message-ID', '<mdn-'.Str::uuid().'@tracepharma>');
    }
}
