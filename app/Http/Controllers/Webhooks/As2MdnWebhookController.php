<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Actions\Integrations\ProcessAs2AsyncMdn;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Support\Integrations\As2MdnWebhookAuthenticator;
use App\Support\Tenancy\AssertWebhookTenantMatchesHost;
use App\Support\Tenancy\TenantAccess;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class As2MdnWebhookController
{
    public function __construct(
        private readonly As2MdnWebhookAuthenticator $authenticator,
        private readonly ProcessAs2AsyncMdn $process,
    ) {}

    public function handle(Request $request, string $tenantId, int $connectionId): JsonResponse
    {
        AssertWebhookTenantMatchesHost::assert($tenantId);

        $tenant = Tenant::query()->findOrFail($tenantId);

        TenantAccess::assertActive($tenant);

        TenantKillSwitches::forTenant($tenant)->assertNotKilled(TenantKillSwitches::OUTBOUND_EPCIS);

        return TenantRunner::run($tenant, function () use ($request, $connectionId): JsonResponse {
            $connection = OutboundConnection::query()->findOrFail($connectionId);

            $this->authenticator->authorize($request, $connection);

            $result = $this->process->handle(
                request: $request,
                connection: $connection,
                rawBody: $request->getContent(),
            );

            return response()->json($result);
        });
    }
}
