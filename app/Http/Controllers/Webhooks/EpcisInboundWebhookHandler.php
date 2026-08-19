<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Exceptions\DuplicateEpcisUploadException;
use App\Models\InboundConnection;
use App\Services\Integrations\InboundConnectionLogger;
use App\Services\Integrations\InboundEpcisReceiver;
use App\Services\Integrations\InboundPayloadResolver;
use App\Support\Integrations\InboundConnectivityProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class EpcisInboundWebhookHandler
{
    public function __construct(
        private readonly InboundConnectionLogger $logger,
        private readonly InboundPayloadResolver $payloadResolver,
        private readonly InboundEpcisReceiver $receiver,
    ) {}

    /**
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    public function extractPayload(Request $request, InboundConnection $connection): array
    {
        /** @var UploadedFile|null $uploaded */
        $uploaded = $request->file('file');

        if ($uploaded instanceof UploadedFile) {
            return [
                (string) file_get_contents($uploaded->getRealPath()),
                $uploaded->getClientOriginalName(),
                $uploaded->getClientMimeType() ?: $request->header('Content-Type'),
            ];
        }

        $content = $request->getContent();

        if ($content !== '') {
            return [$content, $request->header('X-Original-Filename'), $request->header('Content-Type')];
        }

        $this->logger->log($connection, 'receive', 'failed', 'No EPCIS payload found in request.');

        abort(422, 'No EPCIS payload found in request.');
    }

    public function process(
        Request $request,
        InboundConnection $connection,
        string $rawBody,
        ?string $originalName,
        ?string $contentType,
        string $receivedVia = 'https_webhook',
    ): JsonResponse {
        $resolved = $this->payloadResolver->resolve($rawBody, $contentType, $originalName);
        $content = $resolved['content'];

        if (InboundConnectivityProbe::isProbe($content)) {
            $this->logger->log($connection, 'connectivity_test', 'success', 'Partner connectivity test acknowledged.', [
                'received_via' => $receivedVia,
                'remote_ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Connectivity test acknowledged.',
                'connectivity_test' => true,
            ], 202);
        }

        try {
            $result = $this->receiver->receive(
                connection: $connection,
                content: $content,
                originalFilename: $resolved['originalName'] ?? $originalName,
                receivedVia: $receivedVia,
                metadata: [
                    'content_type' => $resolved['contentType'] ?? $contentType,
                    'remote_ip' => $request->ip(),
                ],
            );
        } catch (DuplicateEpcisUploadException $e) {
            $existing = $e->existing;

            $this->logger->log($connection, 'receive', 'success', 'Duplicate EPCIS upload; existing document returned.', [
                'received_via' => $receivedVia,
                'remote_ip' => $request->ip(),
                'document_id' => $existing->getKey(),
            ]);

            return response()->json([
                'message' => 'EPCIS document already received.',
                'document_id' => $existing->getKey(),
                'document_uuid' => $existing->document_uuid,
                'status' => $existing->status,
                'duplicate' => true,
            ], 409);
        }

        $document = $result['document'];

        return response()->json([
            'message' => 'EPCIS document accepted for processing.',
            'document_id' => $document->getKey(),
            'document_uuid' => $document->document_uuid,
            'status' => $document->status,
            'trading_partner_id' => $result['trading_partner_id'],
        ], 202);
    }
}
