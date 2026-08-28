<?php

namespace App\Services\Epcis;

use App\Actions\Epcis\DispatchEpcisSubscriptions;
use App\Actions\Epcis\RecordOperationalEpcisCatalogSignal;
use App\Enums\OutboundTransport;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\TransmissionMdn;
use App\Models\OutboundConnection;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use App\Services\Epcis\Outbound\As2OutboundSender;
use App\Services\Epcis\Outbound\As2SendResult;
use App\Services\Epcis\Outbound\HttpsOutboundSender;
use App\Services\Epcis\Outbound\SftpOutboundSender;
use App\Support\Filesystem\SafeFilename;
use App\Support\Integrations\As2MdnDispositionParser;
use App\Support\Integrations\OutboundTransportAvailability;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ConnectionOutboundEpcisTransmitter implements OutboundEpcisTransmitter
{
    private const TRANSMIT_HEARTBEAT_SECONDS = 120;

    public function __construct(
        private readonly OutboundConnectionResolver $resolver,
        private readonly HttpsOutboundSender $httpsSender,
        private readonly SftpOutboundSender $sftpSender,
        private readonly As2OutboundSender $as2Sender,
        private readonly As2MdnDispositionParser $dispositionParser,
        private readonly RecordOperationalEpcisCatalogSignal $catalogSignal,
    ) {}

    public function hasRecentTransmitHeartbeat(EpcisDocument $document): bool
    {
        $updatedAt = $document->updated_at;

        if ($updatedAt === null) {
            return false;
        }

        return $updatedAt->greaterThanOrEqualTo(now()->subSeconds(self::TRANSMIT_HEARTBEAT_SECONDS));
    }

    public function recoverSentFromPersistedEvidence(EpcisDocument $document): bool
    {
        if ($document->transmission_status === 'sent' && $document->sent_at !== null) {
            return true;
        }

        $mdn = TransmissionMdn::query()
            ->where('document_id', $document->getKey())
            ->whereNotIn('mdn_status', ['superseded', 'failed'])
            ->orderByDesc('id')
            ->first();

        if ($mdn === null) {
            return false;
        }

        $document->forceFill([
            'transmission_status' => 'sent',
            'sent_at' => $document->sent_at ?? $mdn->mdn_received_at ?? now(),
            'error_message' => null,
        ])->save();

        return true;
    }

    public function transmit(EpcisDocument $document, bool $forceRetransmit = false): void
    {
        if ($document->direction !== 'outbound') {
            return;
        }

        $document = $document->fresh() ?? $document;

        if ($document->transmission_status === 'sent' && ! $forceRetransmit) {
            return;
        }

        if (! $forceRetransmit && $this->recoverSentFromPersistedEvidence($document)) {
            return;
        }

        if ($forceRetransmit) {
            $this->supersedePendingTransmissionMdns($document);
        }

        $hasExplicitConnection = $document->outbound_connection_id !== null;

        if (! filled($document->payload_path)) {
            $this->markSkipped($document, $hasExplicitConnection, 'EPCIS payload path is empty.');

            return;
        }

        try {
            $payloadExists = Storage::disk($document->payloadFilesystemDisk())
                ->exists((string) $document->payload_path);
        } catch (Throwable $e) {
            Log::warning('Outbound EPCIS payload storage check failed.', [
                'document_id' => $document->getKey(),
                'error' => $e->getMessage(),
            ]);

            $this->markFailed($document, $hasExplicitConnection, $e->getMessage());

            throw $e;
        }

        if (! $payloadExists) {
            $this->markFailed($document, $hasExplicitConnection, 'EPCIS payload file is missing.');

            throw new RuntimeException('EPCIS payload file is missing.');
        }

        $connection = $this->resolveConnection($document);

        if ($connection === null) {
            $this->markSkipped($document, $hasExplicitConnection, 'No active outbound connection.');

            return;
        }

        try {
            OutboundTransportAvailability::assertTransmittable($connection);

            $content = $this->readPayload($document);
            $filename = SafeFilename::forDownload(
                $document->original_filename,
                (string) $document->payload_path,
            );

            $document->touch();

            $sendResult = match ($connection->transport) {
                OutboundTransport::As2 => $this->as2Sender->send($connection, $content, $filename),
                OutboundTransport::Https => $this->httpsSender->send($connection, $content, $filename),
                OutboundTransport::Sftp => $this->sftpSender->send($connection, $content, $filename),
            };

            $now = now();

            if ($sendResult instanceof As2SendResult) {
                $this->persistTransmissionMdn($document, $connection, $sendResult);

                if (! $sendResult->marksDocumentSent()) {
                    $errorMessage = $this->as2MdnFailureMessage($sendResult);

                    $document->forceFill([
                        'transmission_status' => 'failed',
                        'sent_at' => null,
                        'outbound_connection_id' => $hasExplicitConnection ? $document->outbound_connection_id : $connection->getKey(),
                        'error_message' => $errorMessage,
                    ])->save();

                    $connection->forceFill([
                        'last_error' => $errorMessage,
                    ])->save();

                    $this->catalogSignal->partnerRejected($document, $errorMessage);

                    return;
                }
            }

            $document->forceFill([
                'transmission_status' => 'sent',
                'sent_at' => $now,
                'outbound_connection_id' => $hasExplicitConnection ? $document->outbound_connection_id : $connection->getKey(),
                'error_message' => null,
            ])->save();

            $connection->forceFill([
                'last_sent_at' => $now,
                'last_error' => null,
            ])->save();

            app(DispatchEpcisSubscriptions::class)->handle($document, 'sent');
        } catch (Throwable $e) {
            $message = $e->getMessage();

            Log::warning('Outbound EPCIS transmission failed.', [
                'document_id' => $document->getKey(),
                'connection_id' => $connection->getKey(),
                'error' => $message,
            ]);

            $document->forceFill([
                'transmission_status' => 'failed',
                'outbound_connection_id' => $hasExplicitConnection ? $document->outbound_connection_id : $connection->getKey(),
                'error_message' => $message,
            ])->save();

            $connection->forceFill([
                'last_error' => $message,
            ])->save();

            // A transient failure (timeout, dropped connection, upstream 502/503/504)
            // is not a verdict on this document — TransmitEpcisJob's backoff exists to
            // retry exactly this case, but only fires if the exception reaches it.
            if ($this->isTransientFailure($e)) {
                throw $e;
            }
        }
    }

    private function isTransientFailure(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof \League\Flysystem\UnableToCheckExistence
            || $e instanceof \League\Flysystem\UnableToReadFile) {
            return true;
        }

        $message = strtolower($e->getMessage());

        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return true;
        }

        if (str_contains($message, 'connection refused') || str_contains($message, 'connection reset') || str_contains($message, 'could not resolve host')) {
            return true;
        }

        foreach (['502', '503', '504'] as $code) {
            if (str_contains($message, "http {$code}")) {
                return true;
            }
        }

        return false;
    }

    /**
     * When outbound_connection_id is pinned, honor it fail-closed: missing, inactive, or
     * partner-mismatched pins skip transmission without falling back to the resolver.
     * Unpinned documents use the trading-partner/default resolver.
     */
    private function resolveConnection(EpcisDocument $document): ?OutboundConnection
    {
        if ($document->outbound_connection_id !== null) {
            $explicit = OutboundConnection::query()->find($document->outbound_connection_id);

            if ($explicit === null || ! $explicit->is_active) {
                return null;
            }

            if (! OutboundConnectionResolver::connectionMatchesPartner(
                $explicit,
                $document->trading_partner_id !== null ? (int) $document->trading_partner_id : null,
            )) {
                return null;
            }

            return $explicit;
        }

        return $this->resolver->resolve(
            $document->trading_partner_id !== null ? (int) $document->trading_partner_id : null,
        );
    }

    private function readPayload(EpcisDocument $document): string
    {
        $content = Storage::disk($document->payloadFilesystemDisk())->get((string) $document->payload_path);

        if (! is_string($content) || $content === '') {
            throw new \RuntimeException('EPCIS payload is empty or unreadable.');
        }

        return $content;
    }

    /**
     * A skip means "nothing was sent", not "nothing was chosen". When an operator or a
     * shipping session pinned a connection, that routing decision has to survive the skip
     * so a later retry goes to the same partner instead of the resolver's default.
     */
    private function supersedePendingTransmissionMdns(EpcisDocument $document): void
    {
        TransmissionMdn::query()
            ->where('document_id', $document->getKey())
            ->where('mdn_status', 'pending')
            ->update([
                'mdn_status' => 'superseded',
                'mdn_received_at' => now(),
            ]);
    }

    private function as2MdnFailureMessage(As2SendResult $result): string
    {
        $disposition = null;

        if (is_string($result->mdnBody) && $result->mdnBody !== '') {
            $disposition = $this->dispositionParser->extractDisposition($result->mdnBody);
        }

        if (is_string($disposition) && $disposition !== '') {
            return 'AS2 partner rejected the transmission (MDN failed): '.$disposition;
        }

        return 'AS2 partner rejected the transmission (MDN failed).';
    }

    private function persistTransmissionMdn(
        EpcisDocument $document,
        OutboundConnection $connection,
        As2SendResult $result,
    ): void {
        TransmissionMdn::query()->create([
            'document_id' => $document->getKey(),
            'trading_partner_id' => $connection->trading_partner_id,
            'mdn_status' => $result->mdnStatus,
            'mdn_received_at' => $result->mdnReceivedAt,
            'mdn_payload' => $result->mdnPayload(),
        ]);
    }

    private function markSkipped(EpcisDocument $document, bool $preserveExplicitConnection, string $reason): void
    {
        $attributes = [
            'transmission_status' => 'skipped',
            'sent_at' => null,
            'error_message' => $reason,
        ];

        if (! $preserveExplicitConnection) {
            $attributes['outbound_connection_id'] = null;
        }

        $document->forceFill($attributes)->save();
    }

    private function markFailed(EpcisDocument $document, bool $preserveExplicitConnection, string $message): void
    {
        $attributes = [
            'transmission_status' => 'failed',
            'error_message' => $message,
        ];

        if (! $preserveExplicitConnection) {
            $attributes['outbound_connection_id'] = null;
        }

        $document->forceFill($attributes)->save();
    }
}
