<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\EpcisReceivedVia;
use App\Enums\InboundTransport;
use App\Exceptions\DuplicateEpcisUploadException;
use App\Models\InboundConnection;
use App\Models\Tenant;
use App\Services\Epcis\Inbound\As2InboundMdnFactory;
use App\Services\Epcis\Inbound\As2SmimeUnwrap;
use App\Services\Integrations\InboundEpcisReceiver;
use App\Support\Tenancy\AssertWebhookTenantMatchesHost;
use App\Support\Tenancy\TenantAccess;
use App\Support\Tenancy\TenantKillSwitches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class As2InboundWebhookController
{
    public function __construct(
        private readonly As2SmimeUnwrap $unwrap,
        private readonly As2InboundMdnFactory $mdn,
        private readonly InboundEpcisReceiver $receiver,
    ) {}

    public function handle(
        Request $request,
        string $tenantId,
        int $connectionId,
    ): JsonResponse|Response {
        AssertWebhookTenantMatchesHost::assert($tenantId);

        $tenant = Tenant::query()->findOrFail($tenantId);

        TenantAccess::assertActive($tenant);

        TenantKillSwitches::forTenant($tenant)->assertNotKilled(TenantKillSwitches::INBOUND_EPCIS);

        return $tenant->run(function () use ($request, $connectionId): JsonResponse|Response {
            $connection = InboundConnection::query()
                ->whereKey($connectionId)
                ->where('is_active', true)
                ->where('transport', InboundTransport::As2)
                ->firstOrFail();

            $this->assertAs2Identity($request, $connection);

            $rawBody = $request->getContent();
            if ($rawBody === '') {
                abort(422, 'No AS2 payload found in request.');
            }

            try {
                $xml = $this->unwrap->unwrap($connection, $rawBody, $request->header('Content-Type'));
            } catch (Throwable $e) {
                return $this->finish($request, $connection, processed: false, error: $e->getMessage());
            }

            $filename = $request->header('X-Original-Filename') ?: 'as2-inbound.xml';

            try {
                $result = $this->receiver->receive(
                    connection: $connection,
                    content: $xml,
                    originalFilename: is_string($filename) ? $filename : 'as2-inbound.xml',
                    receivedVia: EpcisReceivedVia::As2Webhook->value,
                    metadata: [
                        'content_type' => $request->header('Content-Type'),
                        'remote_ip' => $request->ip(),
                        'as2_message_id' => $request->header('Message-ID'),
                    ],
                );
            } catch (DuplicateEpcisUploadException $e) {
                return $this->finish(
                    $request,
                    $connection,
                    processed: true,
                    documentId: (int) $e->existing->getKey(),
                    documentUuid: $e->existing->document_uuid,
                    status: $e->existing->status,
                    duplicate: true,
                );
            } catch (Throwable $e) {
                return $this->finish($request, $connection, processed: false, error: $e->getMessage());
            }

            $document = $result['document'];

            return $this->finish(
                $request,
                $connection,
                processed: true,
                documentId: (int) $document->getKey(),
                documentUuid: $document->document_uuid,
                status: $document->status,
            );
        });
    }

    private function assertAs2Identity(Request $request, InboundConnection $connection): void
    {
        $settings = $connection->settings ?? [];
        $expectedFrom = (string) ($settings['as2_from'] ?? '');
        $expectedTo = (string) ($settings['as2_to'] ?? '');
        $from = (string) ($request->header('AS2-From') ?: '');
        $to = (string) ($request->header('AS2-To') ?: '');

        abort_if($expectedFrom === '' || $expectedTo === '', 422, 'AS2 inbound connection is missing as2_from or as2_to.');
        abort_unless(
            hash_equals($expectedFrom, $from) && hash_equals($expectedTo, $to),
            403,
            'AS2-From or AS2-To does not match this inbound connection.',
        );
    }

    private function finish(
        Request $request,
        InboundConnection $connection,
        bool $processed,
        ?string $error = null,
        ?int $documentId = null,
        ?string $documentUuid = null,
        ?string $status = null,
        bool $duplicate = false,
    ): JsonResponse|Response {
        if ($this->mdn->wantsSyncMdn($connection)) {
            $response = $this->mdn->response($request, $connection, $processed, $error);
            if ($documentId !== null) {
                $response->headers->set('X-Document-Id', (string) $documentId);
            }

            return $response;
        }

        if (! $processed) {
            return response()->json([
                'message' => 'AS2 inbound processing failed.',
            ], SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => $duplicate
                ? 'EPCIS document already received.'
                : 'EPCIS document accepted for processing.',
            'document_id' => $documentId,
            'document_uuid' => $documentUuid,
            'status' => $status,
            'duplicate' => $duplicate,
        ], $duplicate ? 409 : 202);
    }
}
