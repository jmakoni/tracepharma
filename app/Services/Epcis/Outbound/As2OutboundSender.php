<?php

namespace App\Services\Epcis\Outbound;

use App\Enums\As2MdnAckMode;
use App\Models\OutboundConnection;
use App\Support\Integrations\As2MdnDispositionParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Lean AS2 outbound sender — POST with AS2 headers, S/MIME CMS when certs are configured,
 * and sync or async MDN capture.
 */
final class As2OutboundSender
{
    public function __construct(
        private readonly As2SmimeEnvelope $smimeEnvelope,
        private readonly As2MdnDispositionParser $dispositionParser,
    ) {}

    public function send(OutboundConnection $connection, string $content, string $filename): As2SendResult
    {
        $settings = $connection->settings ?? [];
        $endpoint = $settings['as2_url'] ?? null;
        $as2From = $settings['as2_from'] ?? null;
        $as2To = $settings['as2_to'] ?? null;

        if (! is_string($endpoint) || $endpoint === '') {
            throw new RuntimeException('AS2 outbound connection is missing settings.as2_url.');
        }

        if (! is_string($as2From) || $as2From === '' || ! is_string($as2To) || $as2To === '') {
            throw new RuntimeException('AS2 outbound connection is missing settings.as2_from or settings.as2_to.');
        }

        $ackMode = As2MdnAckMode::tryFrom((string) ($settings['as2_mdn_ack_mode'] ?? As2MdnAckMode::Sync->value))
            ?? As2MdnAckMode::Sync;

        $credentials = $connection->credentials ?? [];
        $certificatesConfigured = $connection->as2CertificatesConfigured();
        $canSign = filled($credentials['signing_cert_pem'] ?? null) && filled($credentials['signing_key_pem'] ?? null);
        $canEncrypt = filled($credentials['partner_encrypt_cert_pem'] ?? null);

        $body = $content;
        $contentType = 'application/xml';
        $smimeApplied = false;

        if ($canSign || $canEncrypt) {
            try {
                $envelope = $this->smimeEnvelope->envelope(
                    payload: $content,
                    signingCertPem: $canSign ? (string) $credentials['signing_cert_pem'] : null,
                    signingKeyPem: $canSign ? (string) $credentials['signing_key_pem'] : null,
                    partnerEncryptCertPem: $canEncrypt ? (string) $credentials['partner_encrypt_cert_pem'] : null,
                );

                $body = $envelope->body;
                $contentType = $envelope->contentType;
                $smimeApplied = $envelope->smimeApplied;
            } catch (Throwable $e) {
                throw new RuntimeException('AS2 S/MIME envelope failed: '.$e->getMessage(), 0, $e);
            }
        }

        $messageId = '<'.Str::uuid().'@tracepharma>';

        $headers = [
            'AS2-From' => $as2From,
            'AS2-To' => $as2To,
            'Message-ID' => $messageId,
            'Content-Type' => $contentType,
            'Accept' => 'multipart/report, message/disposition-notification, */*',
        ];

        $dispositionNotificationTo = $settings['disposition_notification_to'] ?? null;

        if ($ackMode !== As2MdnAckMode::None && is_string($dispositionNotificationTo) && $dispositionNotificationTo !== '') {
            $headers['Disposition-Notification-To'] = $dispositionNotificationTo;
        }

        $response = Http::timeout(60)
            ->withHeaders($headers)
            ->withBody($body, $contentType)
            ->post($endpoint);

        if (! $response->successful()) {
            throw new RuntimeException(
                "AS2 outbound POST failed (HTTP {$response->status()}): ".substr($response->body(), 0, 500),
            );
        }

        $responseContentType = $response->header('Content-Type');
        $responseBody = $response->body();
        $mdnBody = null;
        $mdnReceivedAt = null;
        $mdnStatus = $ackMode->mdnStatus();

        if ($ackMode === As2MdnAckMode::Sync && $this->looksLikeMdn($responseContentType, $responseBody)) {
            $mdnBody = $responseBody;
            $mdnReceivedAt = now();
            $mdnStatus = $this->dispositionParser->mdnStatusFromBody($responseBody) ?? 'failed';
        }

        return new As2SendResult(
            messageId: $messageId,
            mdnStatus: $mdnStatus,
            mdnReceivedAt: $mdnReceivedAt,
            mdnBody: $mdnBody,
            httpStatus: $response->status(),
            responseHeaders: $response->headers(),
            contentType: is_string($responseContentType) ? $responseContentType : null,
            smimeApplied: $smimeApplied,
            certificatesConfigured: $certificatesConfigured,
        );
    }

    private function looksLikeMdn(?string $contentType, string $body): bool
    {
        if ($body === '' || ! is_string($contentType)) {
            return false;
        }

        $normalized = strtolower($contentType);

        return str_contains($normalized, 'multipart/report')
            || str_contains($normalized, 'message/disposition-notification');
    }
}
