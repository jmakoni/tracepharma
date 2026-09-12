<?php

declare(strict_types=1);

namespace App\Actions\Epcis;

use App\Enums\EpcisAuthoredKind;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Tenant;
use App\Support\Epcis\BuildFullHistoryShippingEpcisXml;
use App\Support\Epcis\OutboundEpcisFilename;
use App\Support\Epcis\PersistEpcisXmlPayload;
use App\Support\Shipping\AssertOutermostSsccHasChildren;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Prepare an outbound document for Retry Transmit / requeue:
 * - Shipping: rebuild full-history TI from current confirmed parents + open tree
 *   (new InstanceIdentifier + prepare-time filename; packing childEPCs filtered to open children).
 * - Other outbound: remint SBDH InstanceIdentifier + prepare-time filename only.
 *
 * Always runs GS1 EPCIS 1.2 / GS1 US R1.3 validation after persist (including portal).
 *
 * @return array{
 *     document: EpcisDocument,
 *     mode: 'rebuild_shipping'|'remint',
 *     old_uuid: string,
 *     new_uuid: string,
 *     old_filename: ?string,
 *     new_filename: string
 * }
 */
class PrepareOutboundEpcisForRetransmit
{
    public function __construct(
        private readonly BuildFullHistoryShippingEpcisXml $buildFullHistoryShippingEpcisXml,
        private readonly PersistEpcisXmlPayload $persistEpcisXmlPayload,
        private readonly RemintOutboundEpcisIdentityForRetransmit $remintOutboundEpcisIdentityForRetransmit,
        private readonly ValidateEpcis12Document $validateEpcis12Document,
        private readonly AssertOutermostSsccHasChildren $assertOutermostSsccHasChildren,
    ) {}

    /**
     * @return array{
     *     document: EpcisDocument,
     *     mode: 'rebuild_shipping'|'remint',
     *     old_uuid: string,
     *     new_uuid: string,
     *     old_filename: ?string,
     *     new_filename: string
     * }
     */
    public function handle(EpcisDocument $document): array
    {
        $document = $document->fresh() ?? $document;

        if ($document->direction !== 'outbound') {
            throw new DomainException('Only outbound EPCIS documents can be prepared for retransmit.');
        }

        $session = $this->resolveShippingSession($document);

        try {
            if ($session instanceof OutboundShippingSession) {
                $result = $this->rebuildShipping($document, $session);
            } else {
                $reminted = $this->remintOutboundEpcisIdentityForRetransmit->handle($document);
                $result = [
                    'document' => $reminted['document'],
                    'mode' => 'remint',
                    'old_uuid' => $reminted['old_uuid'],
                    'new_uuid' => $reminted['new_uuid'],
                    'old_filename' => $reminted['old_filename'],
                    'new_filename' => $reminted['new_filename'],
                ];
            }

            $this->assertGs1ValidOrFail($result['document']);

            return $result;
        } catch (Throwable $e) {
            $message = $this->operatorMessage($e);
            $document->refresh();
            $document->forceFill([
                'transmission_status' => 'failed',
                'error_message' => Str::limit($message, 2000),
            ])->save();

            Log::warning('epcis.outbound.retransmit_prepare_failed', [
                'document_id' => $document->getKey(),
                'message' => $message,
            ]);

            throw $e instanceof DomainException || $e instanceof RuntimeException
                ? $e
                : new DomainException($message, 0, $e);
        }
    }

    /**
     * @return array{
     *     document: EpcisDocument,
     *     mode: 'rebuild_shipping',
     *     old_uuid: string,
     *     new_uuid: string,
     *     old_filename: ?string,
     *     new_filename: string
     * }
     */
    private function rebuildShipping(EpcisDocument $document, OutboundShippingSession $session): array
    {
        $session->loadMissing(['epcisDocument']);

        $parentIds = OutboundShippingScanLine::query()
            ->where('outbound_shipping_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->where('line_role', 'parent')
            ->orderBy('id')
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($parentIds === []) {
            throw new DomainException(
                'Cannot rebuild shipping TI: no confirmed parent EPCs remain on this order. '
                .'Confirm a pallet/case or cancel the empty shipment.',
            );
        }

        foreach (Epc::query()->whereIn('id', $parentIds)->cursor() as $epc) {
            $this->assertOutermostSsccHasChildren->handle($epc);
        }

        $oldUuid = (string) ($document->document_uuid ?? '');
        $oldFilename = filled($document->original_filename) ? (string) $document->original_filename : null;
        $oldPath = (string) ($document->payload_path ?? '');
        $oldDisk = $document->payloadFilesystemDisk();

        $built = $this->buildFullHistoryShippingEpcisXml->handle($session);

        $tenant = tenant();
        if (! $tenant instanceof Tenant) {
            throw new DomainException('Tenant context required to rebuild outbound shipping TI.');
        }

        // Replacement document: keep ship eventTime in XML; stamp filename with prepare time.
        $disk = (string) config('tracepharma.epcis.authored_payload_disk', 'local');
        $allocated = OutboundEpcisFilename::allocateUnique($tenant, Carbon::now('UTC'), 'xml', $disk);
        $filename = $allocated['filename'];
        $path = $allocated['path'];

        $document->forceFill([
            'document_uuid' => $built['instance_id'],
            'original_filename' => $filename,
            'payload_path' => $path,
            'dscsa_affirm' => true,
            'creation_date' => $built['ship_event_time']->copy()->addSeconds(4),
            'error_message' => null,
        ])->save();

        $this->persistEpcisXmlPayload->handle(
            $document,
            $built['xml'],
            $path,
            $disk,
            'Retransmit rebuild outbound shipping EPCIS',
        );

        if (
            $oldPath !== ''
            && ($oldPath !== $path || $oldDisk !== $document->fresh()->payloadFilesystemDisk())
        ) {
            try {
                Storage::disk($oldDisk)->delete($oldPath);
            } catch (Throwable) {
                // Best-effort.
            }
        }

        $document->refresh();

        return [
            'document' => $document,
            'mode' => 'rebuild_shipping',
            'old_uuid' => $oldUuid,
            'new_uuid' => (string) $built['instance_id'],
            'old_filename' => $oldFilename,
            'new_filename' => $filename,
        ];
    }

    private function resolveShippingSession(EpcisDocument $document): ?OutboundShippingSession
    {
        $session = $document->outboundShippingSession;
        if ($session instanceof OutboundShippingSession) {
            return $session;
        }

        $kind = $document->authored_kind;
        if ($kind instanceof EpcisAuthoredKind && $kind === EpcisAuthoredKind::Shipping) {
            throw new DomainException(
                'Cannot rebuild shipping TI: no outbound shipping session is linked to this document.',
            );
        }

        if (is_string($kind) && $kind === EpcisAuthoredKind::Shipping->value) {
            throw new DomainException(
                'Cannot rebuild shipping TI: no outbound shipping session is linked to this document.',
            );
        }

        return null;
    }

    protected function assertGs1ValidOrFail(EpcisDocument $document): void
    {
        $findings = $this->validateEpcis12Document->handle($document->fresh() ?? $document, null, 'outbound');

        $blocking = array_values(array_filter(
            $findings,
            static fn ($finding): bool => is_object($finding) && method_exists($finding, 'isBlocking') && $finding->isBlocking(),
        ));

        if ($blocking === []) {
            return;
        }

        $summaries = [];
        foreach (array_slice($blocking, 0, 5) as $finding) {
            $type = (string) ($finding->exceptionType ?? 'VALIDATION');
            $desc = (string) ($finding->description ?? '');
            $summaries[] = $desc !== '' ? $type.': '.$desc : $type;
        }

        throw new DomainException(
            'Generated EPCIS failed GS1 EPCIS 1.2 / GS1 US R1.3 validation: '
            .Str::limit(implode('; ', $summaries), 1800),
        );
    }

    private function operatorMessage(Throwable $e): string
    {
        $msg = trim($e->getMessage());
        if ($msg === '') {
            return 'Outbound retransmit prepare failed.';
        }

        if (str_contains($msg, 'MISSING_CHILDREN') || str_contains($msg, 'no packed children')) {
            return 'Cannot rebuild shipping TI: a confirmed SSCC has no packed children after unpack. '
                .'Re-pack or remove the empty pallet from the order, then retry.';
        }

        return $msg;
    }
}
