<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\EpcisReceivedVia;
use App\Services\Epcis\Hub\EpcisHubRouter;
use App\Services\Epcis\Inbound\As2InboundMdnFactory;
use App\Services\Epcis\Inbound\As2SmimeUnwrap;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Integrations\PlatformAs2Station;
use App\Support\Tenancy\TenantAccess;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Throwable;

/**
 * Platform-owned AS2 inbound edge. Partners send to the platform station;
 * messages are decrypted with the station keys, verified against the sender
 * registry, then hub-routed to the owning tenant connection.
 */
class As2HubInboundWebhookController
{
    public function __construct(
        private readonly EpcisHubPlatformConfig $platformConfig,
        private readonly PlatformAs2Station $station,
        private readonly As2SmimeUnwrap $unwrap,
        private readonly As2InboundMdnFactory $mdn,
        private readonly EpcisHubRouter $hubRouter,
        private readonly EpcisInboundWebhookHandler $handler,
    ) {}

    public function handle(Request $request): JsonResponse|Response
    {
        $environment = $this->platformConfig->environmentForHost($request->getHost());

        if ($environment === null) {
            abort(404, 'Unknown AS2 hub host.');
        }

        if (! $this->station->isConfigured($environment)) {
            abort(404, 'AS2 hub is not configured for this environment.');
        }

        $stationId = (string) $this->station->stationId($environment);
        $from = trim((string) ($request->header('AS2-From') ?: ''));
        $to = trim((string) ($request->header('AS2-To') ?: ''));

        abort_unless($to !== '' && hash_equals($stationId, $to), 403, 'AS2-To does not match this platform station.');

        $senderCert = $from !== '' ? $this->station->senderCertificate($environment, $from) : null;

        if ($senderCert === null) {
            abort(403, 'Unknown AS2 sender.');
        }

        $rawBody = $request->getContent();

        if ($rawBody === '') {
            return $this->mdn->stationResponse($request, $stationId, $from, false, 'No AS2 payload found in request.');
        }

        try {
            $xml = $this->unwrap->unwrapWithKeys(
                decryptCertPem: $this->station->decryptCertPem($environment),
                decryptKeyPem: $this->station->decryptKeyPem($environment),
                verifyCertPem: $senderCert,
                body: $rawBody,
                contentType: $request->header('Content-Type'),
            );
        } catch (Throwable $exception) {
            return $this->mdn->stationResponse($request, $stationId, $from, false, $exception->getMessage());
        }

        try {
            $resolution = $this->hubRouter->resolve('tracepharma', $xml, $environment);
        } catch (RuntimeException $exception) {
            return $this->mdn->stationResponse($request, $stationId, $from, false, $exception->getMessage());
        }

        if ($resolution->isProbe()) {
            return $this->mdn->stationResponse($request, $stationId, $from, true);
        }

        try {
            // Bind verified AS2-From (S/MIME) to the SBDH sender GLN that selected the route.
            $this->station->assertAs2FromMayClaimSenderGln($environment, $from, $resolution->senderGln);
        } catch (RuntimeException $exception) {
            return $this->mdn->stationResponse($request, $stationId, $from, false, $exception->getMessage());
        }

        $tenant = $resolution->tenant;
        $connection = $resolution->connection;

        try {
            TenantAccess::assertActive($tenant);
            TenantKillSwitches::forTenant($tenant)->assertNotKilled(TenantKillSwitches::INBOUND_EPCIS);

            $response = TenantRunner::run($tenant, function () use ($request, $connection, $xml): JsonResponse {
                return $this->handler->process(
                    request: $request,
                    connection: $connection,
                    rawBody: $xml,
                    originalName: $request->header('X-Original-Filename') ?: 'as2-hub-inbound.xml',
                    contentType: 'application/xml',
                    receivedVia: EpcisReceivedVia::As2Hub->value,
                );
            });
        } catch (Throwable $exception) {
            return $this->mdn->stationResponse($request, $stationId, $from, false, $exception->getMessage());
        }

        $payload = $response->getData(true);
        $processed = in_array($response->getStatusCode(), [202, 409], true);

        $mdn = $this->mdn->stationResponse(
            $request,
            $stationId,
            $from,
            $processed,
            $processed ? null : (string) (is_array($payload) ? ($payload['message'] ?? 'Processing failed.') : 'Processing failed.'),
        );

        if (is_array($payload) && isset($payload['document_id'])) {
            $mdn->headers->set('X-Document-Id', (string) $payload['document_id']);
        }

        return $mdn;
    }
}
