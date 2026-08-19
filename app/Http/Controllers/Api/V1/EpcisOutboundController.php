<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Enums\EpcisReceivedVia;
use App\Exceptions\DuplicateEpcisUploadException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EpcisOutboundRequest;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\TransmissionMdn;
use App\Models\User;
use App\Services\Integrations\InboundPayloadResolver;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Epcis\EpcisApiSiteAccess;
use App\Support\Filesystem\SafeFilename;
use App\Support\Epcis\ScheduleOutboundEpcisTransmission;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\TenantFeatures;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class EpcisOutboundController extends Controller
{
    public function __construct(
        private readonly ReceiveEpcisUpload $receive,
        private readonly InboundPayloadResolver $payloadResolver,
        private readonly ScheduleOutboundEpcisTransmission $scheduleTransmission,
        private readonly EpcisApiSiteAccess $siteAccess,
    ) {}

    public function store(EpcisOutboundRequest $request): JsonResponse
    {
        // Kill switch before profile gate so Admin disable always wins (incl. profiles without outbound).
        TenantKillSwitches::forTenant(tenant())->assertNotKilled(TenantKillSwitches::OUTBOUND_EPCIS);

        if (! TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations()) {
            abort(403, 'Outbound integrations are not enabled for this tenant profile.');
        }

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

            if (! JobRoleAccess::allows(Permissions::NavShip, $user)) {
                abort(403, 'Shipping is not authorized for your job role.');
            }

            $existing = $this->siteAccess->findDuplicate($path, 'outbound');
            if ($existing !== null) {
                return $this->siteAccess->duplicateJsonResponse(
                    new DuplicateEpcisUploadException($existing),
                    $user,
                    'outbound',
                    ['transmission_status' => $existing->transmission_status],
                );
            }

            try {
                $this->siteAccess->assertStoreAllowed($user, $path, 'outbound');
            } catch (AuthorizationException $exception) {
                return response()->json(['message' => $exception->getMessage()], 403);
            }

            try {
                $document = $this->receive->handle($path, [
                    'direction' => 'outbound',
                    'received_via' => EpcisReceivedVia::Api,
                    'original_filename' => $this->normalizeFilename(
                        $resolved['originalName'] ?? $originalName,
                    ),
                    'trading_partner_id' => $request->input('trading_partner_id'),
                    'outbound_connection_id' => $request->input('outbound_connection_id'),
                    'dispatch' => false,
                ]);
            } catch (DuplicateEpcisUploadException $exception) {
                return $this->siteAccess->duplicateJsonResponse(
                    $exception,
                    $user,
                    'outbound',
                    ['transmission_status' => $exception->existing->transmission_status],
                );
            }
        } finally {
            @unlink($path);
        }

        $this->scheduleTransmission->afterPersist($document, true);
        $document = $document->fresh() ?? $document;

        return response()->json([
            'message' => 'EPCIS document accepted for outbound transmission.',
            'document_id' => $document->getKey(),
            'document_uuid' => $document->document_uuid,
            'status' => $document->status,
            'transmission_status' => $document->transmission_status,
        ], 202);
    }

    public function show(Request $request, string $document): JsonResponse
    {
        if (! TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations()) {
            abort(403, 'Outbound integrations are not enabled for this tenant profile.');
        }

        $user = $request->user();
        if ($user !== null && ! JobRoleAccess::allows(Permissions::NavShip, $user)) {
            abort(403, 'Shipping is not authorized for your job role.');
        }

        if (! Str::isUuid($document)) {
            abort(404, 'Outbound EPCIS document not found.');
        }

        $query = EpcisDocument::query()
            ->where('direction', 'outbound')
            ->where('document_uuid', $document);

        if ($user !== null && ! $user->can(Permissions::SitesAccessAll)) {
            $query->whereIn('ship_from_site_id', SiteAccess::userSiteIds($user));
        }

        $record = $query->first();

        if ($record === null) {
            abort(404, 'Outbound EPCIS document not found.');
        }

        return response()->json($this->serializeDocument($record));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDocument(EpcisDocument $document): array
    {
        $mdn = $document->transmissionMdns()->latest('id')->first();

        return [
            'document_id' => $document->getKey(),
            'document_uuid' => $document->document_uuid,
            'status' => $document->status,
            'transmission_status' => $document->transmission_status,
            'sent_at' => $document->sent_at?->toIso8601String(),
            'error_message' => $document->error_message,
            'outbound_connection_id' => $document->outbound_connection_id,
            'trading_partner_id' => $document->trading_partner_id,
            'received_at' => $document->received_at?->toIso8601String(),
            'received_via' => $document->received_via?->value,
            'mdn' => $this->serializeMdn($mdn),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeMdn(?TransmissionMdn $mdn): ?array
    {
        if ($mdn === null) {
            return null;
        }

        $payload = $mdn->mdn_payload ?? [];
        $body = is_array($payload) ? (string) ($payload['body'] ?? '') : '';
        $disposition = null;

        if ($body !== '' && preg_match('/^Disposition:\s*(.+)$/mi', $body, $matches) === 1) {
            $disposition = trim($matches[1]);
        }

        return [
            'status' => $mdn->mdn_status,
            'received_at' => $mdn->mdn_received_at?->toIso8601String(),
            'disposition' => $disposition,
            'http_status' => is_array($payload) ? ($payload['http_status'] ?? null) : null,
        ];
    }

    /**
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function extractPayload(EpcisOutboundRequest $request): array
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
        $tmp = tempnam(sys_get_temp_dir(), 'epcis_api_out_');
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
            'outbound-api-'.now()->format('YmdHis').'.xml',
        );
    }
}
