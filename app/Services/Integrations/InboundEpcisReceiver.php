<?php

namespace App\Services\Integrations;

use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Enums\EpcisReceivedVia;
use App\Models\Epcis\EpcisDocument;
use App\Models\InboundConnection;
use App\Models\TradingPartner;
use App\Rules\ValidGln;
use App\Support\Epcis\SbdhHeaderExtractor;
use App\Support\Epcis\EpcisTempFile;
use App\Support\Filesystem\SafeFilename;
use App\Support\Gs1\Sgln;

/**
 * Accept inbound EPCIS from HTTPS webhooks or SFTP polling via TracePharma's
 * ReceiveEpcisUpload → ProcessEpcisDocument pipeline.
 */
class InboundEpcisReceiver
{
    public function __construct(
        private readonly InboundConnectionLogger $logger,
        private readonly ReceiveEpcisUpload $receive,
        private readonly SbdhHeaderExtractor $sbdhHeader,
    ) {}

    /**
     * @return array{document: EpcisDocument, trading_partner_id: ?int}
     */
    public function receive(
        InboundConnection $connection,
        string $content,
        ?string $originalFilename = null,
        string $receivedVia = 'https_webhook',
        array $metadata = [],
    ): array {
        $tradingPartnerId = $this->resolveTradingPartnerId($connection, $content);
        $filename = $this->normalizeFilename($originalFilename, $content);

        $path = EpcisTempFile::write($content, $filename, 'epcis_inbound_');

        try {
            $channel = EpcisReceivedVia::tryFrom($receivedVia) ?? EpcisReceivedVia::HttpsWebhook;

            $document = $this->receive->handle($path, [
                'direction' => 'inbound',
                'received_via' => $channel,
                'original_filename' => $filename,
                'trading_partner_id' => $tradingPartnerId,
                'inbound_connection_id' => (int) $connection->getKey(),
                'notes' => 'Received via '.$channel->value,
                'dispatch' => true,
                'sync' => false,
            ]);
        } finally {
            @unlink($path);
        }

        $this->logger->log(
            $connection,
            'receive',
            'success',
            "Received {$filename}.",
            array_merge($metadata, [
                'filename' => $filename,
                'received_via' => $receivedVia,
                'document_id' => $document->getKey(),
            ]),
        );

        return [
            'document' => $document,
            'trading_partner_id' => $tradingPartnerId,
        ];
    }

    private function resolveTradingPartnerId(InboundConnection $connection, string $content): ?int
    {
        if ($connection->multiPartnerRoutingEnabled()) {
            $senderGln = $this->extractSenderGln($content);

            if ($senderGln === null) {
                throw new \RuntimeException(
                    'Multi-partner inbound routing requires a valid SBDH sender GLN; unmatched or missing senders are rejected.',
                );
            }

            /** @var TradingPartner|null $match */
            $match = $connection->tradingPartners()
                ->wherePivot('sender_gln', $senderGln)
                ->orderByPivot('priority')
                ->first();

            if ($match === null) {
                throw new \RuntimeException(
                    "Sender GLN [{$senderGln}] is not registered on this multi-partner inbound connection.",
                );
            }

            return (int) $match->getKey();
        }

        return $connection->trading_partner_id !== null
            ? (int) $connection->trading_partner_id
            : null;
    }

    /**
     * The sender's GLN as the SBDH states it — parsed, not pattern-matched.
     *
     * The former regex took the first 13 digits inside any element named like a source,
     * which on a multi-party shipping event is as likely to be the buyer as the sender,
     * and on an SGLN-valued sourceList is nothing at all.
     */
    private function extractSenderGln(string $content): ?string
    {
        $header = $this->sbdhHeader->extract($content);

        // A sender stated as an SGLN normalizes by stripping separators, which turns
        // prefix.reference.extension into 13 digits that are not the GLN — decode it.
        return ValidGln::normalize($header['sender_gln'])
            ?? ValidGln::normalize(Sgln::fromUrn((string) $header['sender_identifier'])['gln'] ?? null);
    }

    private function normalizeFilename(?string $originalFilename, string $content): string
    {
        $fallbackExt = EpcisTempFile::guessExtension($content, $originalFilename);

        return SafeFilename::forUpload(
            $originalFilename,
            'inbound-'.now()->format('YmdHis').'.'.$fallbackExt,
        );
    }
}
