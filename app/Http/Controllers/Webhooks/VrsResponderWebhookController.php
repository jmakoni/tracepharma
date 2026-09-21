<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Vrs\RespondToInboundVerification;
use App\Models\AtpCredential;
use App\Models\Tenant;
use App\Services\Atp\OciVerifyResult;
use App\Services\Atp\OciWalletClient;
use App\Support\Atp\AtpCredentialParser;
use App\Support\Tenancy\AssertWebhookTenantMatchesHost;
use App\Support\Tenancy\TenantAccess;
use App\Support\Tenancy\TenantRunner;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use App\Support\Vrs\Gs1LmsEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class VrsResponderWebhookController
{
    public function __construct(
        private readonly RespondToInboundVerification $respond,
        private readonly OciWalletClient $ociWallet,
    ) {}

    public function handle(Request $request, string $tenantId): JsonResponse
    {
        AssertWebhookTenantMatchesHost::assert($tenantId);

        $tenant = Tenant::query()->findOrFail($tenantId);

        $this->authorizeResponder($request, $tenant);

        TenantAccess::assertActive($tenant);

        return TenantRunner::run($tenant, function () use ($request): JsonResponse {
            if (! TenantFeatures::forTenant(tenant())->supportsVrsResponder()) {
                return response()->json(['message' => 'VRS responder is not enabled for this tenant.'], 403);
            }

            $rejection = $this->captureAtpCredential($request);
            if ($rejection !== null) {
                return $rejection;
            }

            $data = Validator::make(
                Gs1LmsEnvelope::normalizeInboundRequest($request->all()),
                [
                    'gtin14' => ['nullable', 'string', 'max:14'],
                    'gtin' => ['nullable', 'string', 'max:14'],
                    'serial' => ['required', 'string', 'max:255'],
                    'lot' => ['nullable', 'string', 'max:255'],
                    'expiry' => ['nullable', 'string', 'max:16'],
                    'expiry_yymmdd' => ['nullable', 'string', 'max:6'],
                ],
            )->validate();

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
     * OCI seam: capture ATP-Authorization / X-ATP-Credential. Mode off stores
     * parse-only (skipped). Warn/require call the wallet verify adapter; require
     * rejects missing/invalid/expired/error without running product verify.
     * FDA licenses and manual OCI partner evidence are not substitutes.
     *
     * @return JsonResponse|null Rejection when mode=require and ATP fails.
     */
    private function captureAtpCredential(Request $request): ?JsonResponse
    {
        $mode = TenantSettings::forTenant(tenant())->atpOciMode();
        $header = $request->header('ATP-Authorization') ?? $request->header('X-ATP-Credential');
        $header = is_string($header) ? trim($header) : '';

        if ($mode === 'off') {
            if ($header !== '') {
                $this->storeOffMode($header);
            }

            return null;
        }

        if ($header === '') {
            $result = OciVerifyResult::missing();
            $this->persistFromResult(null, $result);

            return $mode === 'require'
                ? response()->json([
                    'message' => 'ATP credential required.',
                    'verification_status' => $result->status,
                ], 401)
                : null;
        }

        $result = $this->ociWallet->verify($header);
        $this->persistFromResult($header, $result);

        if ($mode === 'require' && ! $result->isAcceptable()) {
            $status = $result->status === AtpCredential::STATUS_MISSING ? 401 : 403;

            return response()->json([
                'message' => 'ATP credential verification failed.',
                'verification_status' => $result->status,
                'reason' => $result->reason,
            ], $status);
        }

        return null;
    }

    private function storeOffMode(string $header): void
    {
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
                'verification_status' => AtpCredential::STATUS_SKIPPED,
                'verification_reason' => null,
                'raw_credential' => $header,
            ]);
        } catch (Throwable) {
            // Evidence capture must never break a VRS response in off mode.
        }
    }

    private function persistFromResult(?string $header, OciVerifyResult $result): void
    {
        $parsed = filled($header) ? AtpCredentialParser::parse((string) $header) : null;

        try {
            AtpCredential::query()->create([
                'endpoint' => 'vrs-responder',
                'issuer' => $result->issuer ?? $parsed['issuer'] ?? null,
                'subject_gln' => $result->subjectGln ?? $parsed['subject_gln'] ?? null,
                'credential_expires_at' => $result->expiresAt ?? $parsed['expires_at'] ?? null,
                'header_sha256' => hash('sha256', $header ?? ''),
                'verification_status' => $result->status,
                'verification_reason' => $result->reason,
                'raw_credential' => filled($header) ? $header : null,
            ]);
        } catch (Throwable) {
            // Prefer rejecting in require mode after a best-effort persist attempt.
        }
    }
}
