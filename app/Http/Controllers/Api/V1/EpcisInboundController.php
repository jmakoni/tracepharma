<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Enums\EpcisReceivedVia;
use App\Exceptions\DuplicateEpcisUploadException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EpcisInboundRequest;
use App\Models\User;
use App\Services\Integrations\InboundPayloadResolver;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Epcis\EpcisApiSiteAccess;
use App\Support\Filesystem\SafeFilename;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\TenantFeatures;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

final class EpcisInboundController extends Controller
{
    public function __construct(
        private readonly ReceiveEpcisUpload $receive,
        private readonly InboundPayloadResolver $payloadResolver,
        private readonly EpcisApiSiteAccess $siteAccess,
    ) {}

    public function __invoke(EpcisInboundRequest $request): JsonResponse
    {
        if (! TenantFeatures::forTenant(tenant())->supportsInboundIntegrations()) {
            abort(403, 'Inbound integrations are not enabled for this tenant profile.');
        }

        TenantKillSwitches::forTenant(tenant())->assertNotKilled(TenantKillSwitches::INBOUND_EPCIS);

        [$rawBody, $originalName, $contentType] = $this->extractPayload($request);

        try {
            $resolved = $this->payloadResolver->resolve($rawBody, $contentType, $originalName);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $path = $this->writeTempFile($resolved['content']);

        try {
            $user = $request->user();
            abort_unless($user instanceof User, 401);

            if (! JobRoleAccess::allows(Permissions::NavReceive, $user)) {
                abort(403, 'Receiving is not authorized for your job role.');
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
                return response()->json(['message' => $exception->getMessage()], 403);
            }

            try {
                $document = $this->receive->handle($path, [
                    'direction' => 'inbound',
                    'received_via' => EpcisReceivedVia::Api,
                    'original_filename' => $this->normalizeFilename(
                        $resolved['originalName'] ?? $originalName,
                    ),
                    'dispatch' => true,
                ]);
            } catch (DuplicateEpcisUploadException $exception) {
                return $this->siteAccess->duplicateJsonResponse($exception, $user, 'inbound');
            }
        } finally {
            @unlink($path);
        }

        return response()->json([
            'message' => 'EPCIS document accepted for processing.',
            'document_id' => $document->getKey(),
            'document_uuid' => $document->document_uuid,
            'status' => $document->status,
        ], 202);
    }

    /**
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function extractPayload(EpcisInboundRequest $request): array
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

    private function writeTempFile(string $content): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'epcis_api_');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create temporary EPCIS file.');
        }

        $path = $tmp.'.xml';
        rename($tmp, $path);
        file_put_contents($path, $content);

        return $path;
    }

    private function normalizeFilename(?string $originalFilename): string
    {
        return SafeFilename::forUpload(
            $originalFilename,
            'inbound-api-'.now()->format('YmdHis').'.xml',
        );
    }
}
