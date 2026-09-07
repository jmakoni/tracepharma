<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Vrs\RespondToInboundVerification;
use App\Models\AtpCredential;
use App\Models\Tenant;
use App\Support\Atp\AtpCredentialParser;
use App\Support\Tenancy\AssertWebhookTenantMatchesHost;
use App\Support\Tenancy\TenantAccess;
use App\Support\Tenancy\TenantRunner;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

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

        return TenantRunner::run($tenant, function () use ($request): JsonResponse {
            if (! TenantFeatures::forTenant(tenant())->supportsVrs()) {
                return response()->json(['message' => 'VRS responder is not enabled for this tenant.'], 403);
            }

            $this->captureAtpCredential($request);

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

    /**
     * OCI seam: requesters may present an ATP verifiable credential
     * (ATP-Authorization / X-ATP-Credential header, JWT compact form). Stored
     * as evidence of what was presented; trust-registry verification is deferred.
     */
    private function captureAtpCredential(Request $request): void
    {
        $header = $request->header('ATP-Authorization') ?? $request->header('X-ATP-Credential');

        if (! is_string($header) || trim($header) === '') {
            return;
        }

        $parsed = AtpCredentialParser::parse($header);

        if ($parsed === null) {
            return;
        }

        try {
            AtpCredential::query()->create([
                'endpoint' => 'vrs-responder',
                'issuer' => $parsed['issuer'],
                'subject_gln' => $parsed['subject_gln'],
                'credential_expires_at' => $parsed['expires_at'],
                'header_sha256' => hash('sha256', $header),
                'raw_credential' => $header,
            ]);
        } catch (Throwable) {
            // Evidence capture must never break a VRS response.
        }
    }
}
