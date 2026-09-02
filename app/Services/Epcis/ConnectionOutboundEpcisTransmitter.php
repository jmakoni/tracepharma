<?php

namespace App\Services\Epcis;

use App\Actions\Epcis\DispatchEpcisSubscriptions;
use App\Actions\Epcis\RecordOperationalEpcisCatalogSignal;
use App\Actions\Epcis\ValidateEpcis12Document;
use App\Enums\OutboundTransport;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\TransmissionMdn;
use App\Models\OutboundConnection;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use App\Services\Epcis\Outbound\As2OutboundSender;
use App\Services\Epcis\Outbound\As2SendResult;
use App\Services\Epcis\Outbound\EmailOutboundSender;
use App\Services\Epcis\Outbound\HttpsOutboundSender;
use App\Services\Epcis\Outbound\OutboundEpcisWriterResolver;
use App\Services\Epcis\Outbound\PortalOutboundSender;
use App\Services\Epcis\Outbound\SftpOutboundSender;
use App\Support\Epcis\EpcisSchemaVersion;
use App\Support\Epcis\LiveAcceptedEpcisEventId;
use App\Support\Epcis\Validation\EpcisValidationFinding;
use App\Support\Filesystem\SafeFilename;
use App\Support\Integrations\As2MdnDispositionParser;
use App\Support\Integrations\OutboundTransportAvailability;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToReadFile;
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
        private readonly EmailOutboundSender $emailSender,
        private readonly PortalOutboundSender $portalSender,
        private readonly As2MdnDispositionParser $dispositionParser,
        private readonly RecordOperationalEpcisCatalogSignal $catalogSignal,
        private readonly ValidateEpcis12Document $validateEpcis12Document,
        private readonly OutboundEpcisWriterResolver $writerResolver,
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

        $skipPreTransmitValidation = false;
        if ($document->outbound_connection_id !== null) {
            $pinned = OutboundConnection::query()->find($document->outbound_connection_id);
            $skipPreTransmitValidation = $pinned?->transport === OutboundTransport::Portal;
        }

        // Portal transport publishes in-app; core EPCIS 1.2 XSD does not model GS1 US HC
        // directPurchase inside ObjectEvent extension (buyer still downloads the authored file).
        if (! $skipPreTransmitValidation && ! $this->passesPreTransmitValidation($document, $hasExplicitConnection)) {
            return;
        }

        $document = $document->fresh() ?? $document;

        $replayed = 0;
        $liveAccepted = app(LiveAcceptedEpcisEventId::class);
        $eventIdsQuery = EpcisEvent::query()
            ->where('document_id', $document->getKey())
            ->whereNotNull('event_id')
            ->where('event_id', '!=', '');

        if (Schema::hasColumn('epcis_events', 'superseded_at')) {
            $eventIdsQuery->whereNull('superseded_at');
        }

        if (
            Schema::hasColumn('epcis_events', 'ingest_generation')
            && $document->ingest_generation !== null
        ) {
            $eventIdsQuery->where('ingest_generation', (int) $document->ingest_generation);
        }

        foreach ($eventIdsQuery->pluck('event_id') as $eventId) {
            if ($liveAccepted->existsOnOtherDocument((string) $eventId, (int) $document->getKey())) {
                $replayed++;
            }
        }

        if ($replayed > 0) {
            $this->markSkipped(
                $document,
                $hasExplicitConnection,
                'Enterprise event-id already accepted; transmit skipped. accepted_event_ids='.$replayed,
            );

            return;
        }

        $connection = $this->resolveConnection($document);

        if ($connection === null) {
            $this->markSkipped($document, $hasExplicitConnection, 'No active outbound connection.');

            return;
        }

        if ($this->isJsonLdDocument($document)
            && $this->writerResolver->versionForConnection($connection) === EpcisSchemaVersion::V12) {
            $this->markFailed(
                $document,
                $hasExplicitConnection,
                'Outbound connection is EPCIS 1.2; JSON-LD payloads cannot be transmitted.',
            );

            return;
        }

        try {
            OutboundTransportAvailability::assertTransmittable($connection);

            $content = $this->readPayload($document);
            $filename = SafeFilename::forDownload(
                $document->original_filename,
                (string) $document->payload_path,
            );
            $contentType = $this->contentTypeForDocument($document);

            $document->touch();

            $sendResult = match ($connection->transport) {
                OutboundTransport::As2 => $this->as2Sender->send($connection, $content, $filename, $contentType),
                OutboundTransport::Https => $this->httpsSender->send($connection, $content, $filename, $contentType),
                OutboundTransport::Sftp => $this->sftpSender->send($connection, $content, $filename),
                OutboundTransport::Email => $this->emailSender->send(
                    $connection,
                    $content,
                    $filename,
                    $contentType,
                    $document,
                ),
                OutboundTransport::Portal => $this->portalSender->send($connection, $document),
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
            } elseif ($connection->transport === OutboundTransport::Https) {
                // Persist HTTPS partner-ack evidence before marking sent so a crash
                // between POST success and the document update can recover without
                // a second POST (same role as AS2 TransmissionMdn rows).
                $this->persistHttpsAckEvidence($document, $connection, $now);
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

        if ($e instanceof UnableToCheckExistence
            || $e instanceof UnableToReadFile) {
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
     * Run XSD/JSON Schema + catalog validation on the persisted outbound payload.
     * On blocking findings: mark transmission failed and return false (do not throw —
     * validation failures are not transient retries).
     */
    private function passesPreTransmitValidation(EpcisDocument $document, bool $hasExplicitConnection): bool
    {
        $findings = $this->validateEpcis12Document->handle($document, null, 'outbound');

        $blocking = array_values(array_filter(
            $findings,
            static fn (EpcisValidationFinding $finding): bool => $finding->isBlocking(),
        ));

        if ($blocking === []) {
            return true;
        }

        $summaries = array_map(
            static fn (EpcisValidationFinding $finding): string => $finding->exceptionType.': '.$finding->description,
            array_slice($blocking, 0, 5),
        );

        $message = 'Pre-transmit EPCIS validation failed: '.Str::limit(implode('; ', $summaries), 1800);

        Log::warning('Outbound EPCIS pre-transmit validation blocked send.', [
            'document_id' => $document->getKey(),
            'blocking_count' => count($blocking),
        ]);

        $this->markFailed($document->fresh() ?? $document, $hasExplicitConnection, $message);

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

        return $this->resolver->resolveWithLadder(
            $document->trading_partner_id !== null ? (int) $document->trading_partner_id : null,
        );
    }

    private function readPayload(EpcisDocument $document): string
    {
        $content = Storage::disk($document->payloadFilesystemDisk())->get((string) $document->payload_path);

        if (! is_string($content) || $content === '') {
            throw new RuntimeException('EPCIS payload is empty or unreadable.');
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

    /**
     * HTTPS has no MDN MIME body; record a durable ack so recoverSentFromPersistedEvidence
     * can mark the document sent without re-POSTing after a crash between HTTP success
     * and the transmission_status update.
     */
    private function persistHttpsAckEvidence(
        EpcisDocument $document,
        OutboundConnection $connection,
        Carbon $receivedAt,
    ): void {
        TransmissionMdn::query()->create([
            'document_id' => $document->getKey(),
            'trading_partner_id' => $connection->trading_partner_id,
            'mdn_status' => 'https_ack',
            'mdn_received_at' => $receivedAt,
            'mdn_payload' => [
                'transport' => 'https',
                'outbound_connection_id' => $connection->getKey(),
            ],
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

    private function isJsonLdDocument(EpcisDocument $document): bool
    {
        return $document->format === EpcisSchemaVersion::FORMAT_JSON
            || $document->schema_version === EpcisSchemaVersion::V20;
    }

    private function contentTypeForDocument(EpcisDocument $document): string
    {
        return $document->format === EpcisSchemaVersion::FORMAT_JSON
            ? 'application/ld+json'
            : 'application/xml';
    }
}
