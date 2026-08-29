<?php

namespace App\Services\Epcis\Outbound;

use App\Models\OutboundConnection;
use App\Support\Filesystem\SafeFilename;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpsOutboundSender
{
    public function send(
        OutboundConnection $connection,
        string $content,
        string $filename,
        ?string $contentType = null,
    ): void {
        $contentType ??= 'application/xml';

        $settings = $connection->settings ?? [];
        $endpoint = $settings['endpoint_url'] ?? $settings['webhook_url'] ?? null;

        if (! is_string($endpoint) || $endpoint === '') {
            throw new RuntimeException('HTTPS outbound connection is missing settings.endpoint_url or settings.webhook_url.');
        }

        $credentials = $connection->credentials ?? [];
        $token = $credentials['webhook_token'] ?? $credentials['inbound_token'] ?? null;

        $attachmentName = SafeFilename::forDownload($filename, $filename);

        $request = Http::timeout(60)
            ->withHeaders([
                'Content-Type' => $contentType,
                'Accept' => 'application/xml',
                'Content-Disposition' => 'attachment; filename="'.$attachmentName.'"',
            ])
            ->withBody($content, $contentType);

        if (is_string($token) && $token !== '') {
            $request = $request->withHeaders(['X-Inbound-Token' => $token]);
        }

        $response = $request->post($endpoint);

        if (! $response->successful()) {
            throw new RuntimeException(
                "HTTPS outbound POST failed (HTTP {$response->status()}): ".substr($response->body(), 0, 500),
            );
        }
    }
}
