<?php

namespace App\Services\Epcis\Outbound;

use App\Models\OutboundConnection;
use App\Support\Epcis\EpcisSubscriptionUrl;
use App\Support\EpcisHub\PlatformOutboundEgress;
use App\Support\Filesystem\SafeFilename;
use RuntimeException;

final class HttpsOutboundSender
{
    public function __construct(
        private readonly PlatformOutboundEgress $egress,
    ) {}

    public function send(
        OutboundConnection $connection,
        string $content,
        string $filename,
        ?string $contentType = null,
    ): void {
        $contentType ??= 'application/xml';

        [$endpoint, $token] = $this->resolveEndpointAndToken($connection);

        EpcisSubscriptionUrl::assertSafeTargetUrl($endpoint);

        $attachmentName = SafeFilename::forDownload($filename, $filename);

        $request = EpcisSubscriptionUrl::httpClient($endpoint, 60)
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
                "HTTPS outbound POST failed (HTTP {$response->status()}).",
            );
        }
    }

    /**
     * Lightweight reachability check — does not POST EPCIS content.
     *
     * @return array{ok: bool, message: string, status: ?int}
     */
    public function probe(OutboundConnection $connection): array
    {
        try {
            [$endpoint, $token] = $this->resolveEndpointAndToken($connection);
            EpcisSubscriptionUrl::assertSafeTargetUrl($endpoint);

            $request = EpcisSubscriptionUrl::httpClient($endpoint, 10)
                ->withHeaders(['Accept' => '*/*']);

            if (is_string($token) && $token !== '') {
                $request = $request->withHeaders(['X-Inbound-Token' => $token]);
            }

            $response = $request->head($endpoint);

            // Some hubs reject HEAD; fall back to OPTIONS then GET without body.
            if ($response->status() === 405 || $response->status() === 501) {
                $response = $request->get($endpoint);
            }

            $status = $response->status();
            $reachable = $status > 0 && $status < 500;

            $message = match (true) {
                ! $reachable => "Endpoint returned HTTP {$status}.",
                $status === 405 => 'Endpoint reachable — accepts POST only (HTTP 405 to probe).',
                $status === 401 || $status === 403 => "Endpoint reachable — token rejected (HTTP {$status}).",
                default => "Endpoint responded HTTP {$status}.",
            };

            return [
                'ok' => $reachable,
                'message' => $message,
                'status' => $status,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'status' => null,
            ];
        }
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function resolveEndpointAndToken(OutboundConnection $connection): array
    {
        // Platform egress: hub-linked systech/unitrace connections send through
        // the TracePharma-owned network edge unless the tenant overrode the endpoint.
        $edge = $this->egress->edgeFor($connection);

        if ($edge !== null) {
            return [$edge['url'], $edge['token']];
        }

        $endpoint = $connection->effectiveEndpointUrl();

        if (! is_string($endpoint) || $endpoint === '') {
            throw new RuntimeException('HTTPS outbound connection is missing settings.endpoint_url or settings.webhook_url.');
        }

        $credentials = $connection->credentials ?? [];
        $token = $credentials['webhook_token'] ?? $credentials['inbound_token'] ?? null;

        return [
            $endpoint,
            is_string($token) && $token !== '' ? $token : null,
        ];
    }
}
