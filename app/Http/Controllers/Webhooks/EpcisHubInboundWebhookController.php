<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Services\Epcis\Hub\EpcisHubRouter;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Integrations\EpcisHubAuthenticator;
use App\Support\Tenancy\TenantAccess;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EpcisHubInboundWebhookController
{
    public function __construct(
        private readonly EpcisHubAuthenticator $hubAuth,
        private readonly EpcisInboundWebhookHandler $handler,
        private readonly EpcisHubRouter $hubRouter,
        private readonly EpcisHubPlatformConfig $platformConfig,
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower(trim($provider));

        $environment = $this->hubAuth->authorize($request);

        if (! in_array($provider, $this->platformConfig->enabledProviders($environment), true)) {
            abort(404, 'Unknown EPCIS hub provider.');
        }

        [$rawBody, $originalName, $contentType] = $this->extractPayload($request);

        try {
            $resolution = $this->hubRouter->resolve($provider, $rawBody, $environment);
        } catch (\RuntimeException $exception) {
            $status = str_contains($exception->getMessage(), 'multiple') ? 409 : 422;

            throw new HttpException($status, $exception->getMessage(), $exception);
        }

        if ($resolution->isProbe()) {
            return response()->json([
                'message' => 'Connectivity test acknowledged.',
                'connectivity_test' => true,
            ], 202);
        }

        $tenant = $resolution->tenant;
        $connection = $resolution->connection;

        TenantAccess::assertActive($tenant);

        TenantKillSwitches::forTenant($tenant)->assertNotKilled(TenantKillSwitches::INBOUND_EPCIS);

        return TenantRunner::run($tenant, function () use ($request, $connection, $rawBody, $originalName, $contentType): JsonResponse {
            return $this->handler->process(
                request: $request,
                connection: $connection,
                rawBody: $rawBody,
                originalName: $originalName,
                contentType: $contentType,
                receivedVia: 'https_webhook_hub',
            );
        });
    }

    /**
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function extractPayload(Request $request): array
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

        abort(422, 'No EPCIS payload found in request.');
    }
}
