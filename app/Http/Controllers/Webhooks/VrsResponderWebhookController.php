<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Vrs\RespondToInboundVerification;
use App\Models\Tenant;
use App\Support\Tenancy\AssertWebhookTenantMatchesHost;
use App\Support\Tenancy\TenantAccess;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VrsResponderWebhookController
{
    public function __construct(
        private readonly RespondToInboundVerification $respond,
    ) {}

    public function handle(Request $request, string $tenantId): JsonResponse
    {
        AssertWebhookTenantMatchesHost::assert($tenantId);

        $tenant = Tenant::query()->findOrFail($tenantId);

        $this->authorizeResponder($request, $tenant);

        TenantAccess::assertActive($tenant);

        return $tenant->run(function () use ($request): JsonResponse {
            if (! TenantFeatures::forTenant(tenant())->supportsVrs()) {
                return response()->json(['message' => 'VRS responder is not enabled for this tenant.'], 403);
            }

            $data = $request->validate([
                'gtin14' => ['nullable', 'string', 'max:14'],
                'gtin' => ['nullable', 'string', 'max:14'],
                'serial' => ['required', 'string', 'max:255'],
                'lot' => ['nullable', 'string', 'max:255'],
                'expiry' => ['nullable', 'string', 'max:16'],
                'expiry_yymmdd' => ['nullable', 'string', 'max:6'],
            ]);

            $gtin = $data['gtin14'] ?? $data['gtin'] ?? null;
            if (! filled($gtin)) {
                return response()->json(['message' => 'gtin14 or gtin is required.'], 422);
            }

            $expiry = $data['expiry_yymmdd'] ?? null;
            if ($expiry === null && filled($data['expiry'] ?? null)) {
                $digits = preg_replace('/\D+/', '', (string) $data['expiry']) ?? '';
                if (strlen($digits) === 8) {
                    $digits = substr($digits, 2);
                }
                $expiry = strlen($digits) === 6 ? $digits : null;
            }

            $result = $this->respond->handle(
                (string) $gtin,
                (string) $data['serial'],
                filled($data['lot'] ?? null) ? (string) $data['lot'] : null,
                $expiry,
                $request->all(),
            );

            $verification = $result['verification'];

            return response()->json([
                'status' => $result['status'],
                'message' => $result['message'],
                'gtin14' => $verification->gtin14,
                'serial' => $verification->serial,
                'lot' => $verification->lot,
                'verification_id' => $verification->getKey(),
                'found' => $result['found'],
            ], $result['found'] ? 200 : 404);
        });
    }

    private function authorizeResponder(Request $request, Tenant $tenant): void
    {
        $configured = $this->resolveResponderApiKey($tenant);

        if ($configured === '') {
            abort(503, 'VRS responder is not configured for this tenant.');
        }

        $provided = (string) ($request->header('X-Vrs-Api-Key')
            ?? $request->bearerToken()
            ?? '');

        if ($provided === '' || ! hash_equals($configured, $provided)) {
            abort(401, 'Invalid VRS responder credentials.');
        }
    }

    private function resolveResponderApiKey(Tenant $tenant): string
    {
        return TenantSettings::forTenant($tenant)->vrsResponderApiKey() ?? '';
    }
}
