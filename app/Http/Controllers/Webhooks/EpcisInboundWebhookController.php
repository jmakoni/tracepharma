<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\InboundTransport;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Support\Integrations\InboundWebhookAuthenticator;
use App\Support\Tenancy\AssertWebhookTenantMatchesHost;
use App\Support\Tenancy\TenantAccess;
use App\Support\Tenancy\TenantKillSwitches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EpcisInboundWebhookController
{
    public function __construct(
        private readonly InboundWebhookAuthenticator $webhookAuth,
        private readonly EpcisInboundWebhookHandler $handler,
    ) {}

    public function handle(
        Request $request,
        string $tenantId,
        int $connectionId,
    ): JsonResponse {
        AssertWebhookTenantMatchesHost::assert($tenantId);

        $tenant = Tenant::query()->findOrFail($tenantId);

        TenantAccess::assertActive($tenant);

        TenantKillSwitches::forTenant($tenant)->assertNotKilled(TenantKillSwitches::INBOUND_EPCIS);

        return $tenant->run(function () use ($request, $connectionId): JsonResponse {
            $connection = InboundConnection::query()
                ->whereKey($connectionId)
                ->where('is_active', true)
                ->where('transport', InboundTransport::Https)
                ->firstOrFail();

            $this->webhookAuth->authorize($request, $connection);

            [$rawBody, $originalName, $contentType] = $this->handler->extractPayload($request, $connection);

            return $this->handler->process(
                request: $request,
                connection: $connection,
                rawBody: $rawBody,
                originalName: $originalName,
                contentType: $contentType,
            );
        });
    }
}
