<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Enums\EpcisReceivedVia;
use App\Exceptions\DuplicateEpcisUploadException;
use App\Http\Controllers\Controller;
use App\Models\Epcis\EpcisDocument;
use App\Models\User;
use App\Services\Integrations\InboundPayloadResolver;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Epcis\EpcisApiSiteAccess;
use App\Support\Epcis\EpcisTempFile;
use App\Support\Filesystem\SafeFilename;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\TenantFeatures;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * GS1-shaped Capture REST (Phase 1): POST capture + GET capture status.
 */
final class EpcisCaptureController extends Controller
{
    public function __construct(
        private readonly ReceiveEpcisUpload $receive,
        private readonly InboundPayloadResolver $payloadResolver,
        private readonly EpcisApiSiteAccess $siteAccess,
    ) {}

    public function store(Request $request): JsonResponse
    {
        if (! TenantFeatures::forTenant(tenant())->supportsInboundIntegrations()) {
            return $this->securityException('Inbound integrations are not enabled for this tenant profile.');
        }

        try {
            TenantKillSwitches::forTenant(tenant())->assertNotKilled(TenantKillSwitches::INBOUND_EPCIS);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->securityException($e->getMessage());
        }

        [$rawBody, $originalName, $contentType] = $this->extractPayload($request);

        try {
            $resolved = $this->payloadResolver->resolve($rawBody, $contentType, $originalName);
        } catch (\InvalidArgumentException $exception) {
            return $this->captureInvalid($exception->getMessage());
        }

        $path = EpcisTempFile::write($resolved['content'], $resolved['originalName'] ?? $originalName, 'epcis_capture_');

        try {
            $user = $request->user();
            abort_unless($user instanceof User, 401);

            if (! JobRoleAccess::allows(Permissions::NavReceive, $user)) {
                return $this->securityException('Receiving is not authorized for your job role.');
            }

            $existing = $this->siteAccess->findDuplicate($path, 'inbound');
            if ($existing !== null) {
                return $this->siteAccess->duplicateJsonResponse(
                    new DuplicateEpcisUploadException($existing),
                    $user,
                    'inbound',
                );
            }

            try {
                $this->siteAccess->assertStoreAllowed($user, $path, 'inbound');
            } catch (AuthorizationException $exception) {
                return $this->securityException($exception->getMessage());
            }

            try {
                $document = $this->receive->handle($path, [
                    'direction' => 'inbound',
                    'received_via' => EpcisReceivedVia::Api,
                    'original_filename' => SafeFilename::forUpload(
                        $resolved['originalName'] ?? $originalName,
                        'capture-'.now()->format('YmdHis').'.'.EpcisTempFile::guessExtension($resolved['content'], $resolved['originalName'] ?? $originalName),
                    ),
                    'dispatch' => true,
                ]);
            } catch (DuplicateEpcisUploadException $exception) {
                return $this->siteAccess->duplicateJsonResponse($exception, $user, 'inbound');
            } catch (\InvalidArgumentException $exception) {
                return $this->captureInvalid($exception->getMessage());
            }
        } finally {
            @unlink($path);
        }

        $captureId = (int) $document->getKey();
        $location = url('/api/v1/epcis/capture/'.$captureId);

        return response()->json([
            'type' => 'CaptureAccepted',
            'captureID' => (string) $captureId,
            'status' => $this->mapStatus((string) $document->status),
            'document_uuid' => $document->document_uuid,
        ], 202, [
            'Location' => $location,
        ]);
    }

    public function show(Request $request, int $captureId): JsonResponse
    {
        if (! TenantFeatures::forTenant(tenant())->supportsInboundIntegrations()) {
            return $this->securityException('Inbound integrations are not enabled for this tenant profile.');
        }

        try {
            TenantKillSwitches::forTenant(tenant())->assertNotKilled(TenantKillSwitches::INBOUND_EPCIS);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->securityException($e->getMessage());
        }

        $document = EpcisDocument::query()->find($captureId);
        if ($document === null) {
            return response()->json([
                'type' => 'NoSuchResourceException',
                'message' => 'Capture job not found.',
            ], 404);
        }

        /** @var User|null $user */
        $user = $request->user();
        if ($user !== null && ! $this->siteAccess->callerCanAccessDocument($user, $document, (string) $document->direction)) {
            return $this->securityException('You do not have access to this capture job.');
        }

        return response()->json([
            'captureID' => (string) $document->getKey(),
            'status' => $this->mapStatus((string) $document->status),
            'document_uuid' => $document->document_uuid,
            'error_message' => $document->error_message,
        ]);
    }

    private function mapStatus(string $status): string
    {
        return match ($status) {
            'parsed', 'validated', 'generated' => 'accepted',
            'error', 'voided', 'cancelled' => 'failed',
            default => 'pending',
        };
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

        return [
            $request->getContent(),
            $request->header('X-Original-Filename'),
            $request->header('Content-Type'),
        ];
    }

    private function captureInvalid(string $message): JsonResponse
    {
        return response()->json([
            'type' => 'CaptureInvalid',
            'message' => $message,
        ], 422);
    }

    private function securityException(string $message): JsonResponse
    {
        return response()->json([
            'type' => 'SecurityException',
            'message' => $message,
        ], 403);
    }
}
