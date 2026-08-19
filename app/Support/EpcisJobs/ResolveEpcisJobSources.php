<?php

declare(strict_types=1);

namespace App\Support\EpcisJobs;

use App\Enums\EpcisAuthoredKind;
use App\Enums\EpcisJobKind;
use App\Enums\EpcisReceivedVia;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\SsccLabelBatch;
use App\Models\Transferring\TransferringSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ResolveEpcisJobSources
{
    /**
     * @return array{
     *     kind: EpcisJobKind,
     *     outbound_shipping_session_id: ?int,
     *     receiving_session_id: ?int,
     *     transferring_session_id: ?int,
     *     sscc_label_batch_id: ?int,
     *     ship_from_site_id: ?int
     * }|null
     */
    public function fromDocument(EpcisDocument $document): ?array
    {
        if ($document->direction === 'outbound' && $document->received_via === EpcisReceivedVia::Api) {
            return $this->disposition($document, EpcisJobKind::OutboundApi);
        }

        $authored = $document->authored_kind;
        if (! $authored instanceof EpcisAuthoredKind) {
            $authored = EpcisAuthoredKind::inferAuthoredKindFromNotesAndFilename(
                (string) ($document->notes ?? ''),
                (string) ($document->original_filename ?? ''),
            );
        }

        if (! $authored instanceof EpcisAuthoredKind) {
            return null;
        }

        $kind = EpcisJobKind::fromAuthoredKind($authored);
        $docId = (int) $document->getKey();

        return match ($authored) {
            EpcisAuthoredKind::Shipping => $this->shipping($docId, $document),
            EpcisAuthoredKind::Receiving => $this->receiving($docId, $document),
            EpcisAuthoredKind::Transferring => $this->transferring($docId, $document),
            EpcisAuthoredKind::SsccCommissioning,
            EpcisAuthoredKind::SsccAggregation,
            EpcisAuthoredKind::SsccDisaggregation => $this->sscc($docId, $document, $kind),
            EpcisAuthoredKind::Decommissioning,
            EpcisAuthoredKind::Returning,
            EpcisAuthoredKind::Commissioning => $this->disposition($document, $kind),
        };
    }

    /**
     * @return array{
     *     kind: EpcisJobKind,
     *     outbound_shipping_session_id: ?int,
     *     receiving_session_id: ?int,
     *     transferring_session_id: ?int,
     *     sscc_label_batch_id: ?int,
     *     ship_from_site_id: ?int
     * }
     */
    private function disposition(EpcisDocument $document, EpcisJobKind $kind): array
    {
        return [
            'kind' => $kind,
            'outbound_shipping_session_id' => null,
            'receiving_session_id' => null,
            'transferring_session_id' => null,
            'sscc_label_batch_id' => null,
            'ship_from_site_id' => $document->ship_from_site_id,
        ];
    }

    /**
     * @return array{
     *     kind: EpcisJobKind,
     *     outbound_shipping_session_id: ?int,
     *     receiving_session_id: ?int,
     *     transferring_session_id: ?int,
     *     sscc_label_batch_id: ?int,
     *     ship_from_site_id: ?int
     * }
     */
    private function shipping(int $docId, EpcisDocument $document): array
    {
        $session = OutboundShippingSession::query()
            ->where('epcis_document_id', $docId)
            ->first(['id', 'site_id']);

        return [
            'kind' => EpcisJobKind::OutboundShipping,
            'outbound_shipping_session_id' => $session?->id,
            'receiving_session_id' => null,
            'transferring_session_id' => null,
            'sscc_label_batch_id' => null,
            'ship_from_site_id' => $session?->site_id ?? $document->ship_from_site_id,
        ];
    }

    /**
     * @return array{
     *     kind: EpcisJobKind,
     *     outbound_shipping_session_id: ?int,
     *     receiving_session_id: ?int,
     *     transferring_session_id: ?int,
     *     sscc_label_batch_id: ?int,
     *     ship_from_site_id: ?int
     * }
     */
    private function receiving(int $docId, EpcisDocument $document): array
    {
        $session = ReceivingSession::query()
            ->where('receiving_epcis_document_id', $docId)
            ->first(['id', 'site_id']);

        return [
            'kind' => EpcisJobKind::OutboundReceiving,
            'outbound_shipping_session_id' => null,
            'receiving_session_id' => $session?->id,
            'transferring_session_id' => null,
            'sscc_label_batch_id' => null,
            'ship_from_site_id' => $session?->site_id ?? $document->ship_from_site_id,
        ];
    }

    /**
     * @return array{
     *     kind: EpcisJobKind,
     *     outbound_shipping_session_id: ?int,
     *     receiving_session_id: ?int,
     *     transferring_session_id: ?int,
     *     sscc_label_batch_id: ?int,
     *     ship_from_site_id: ?int
     * }
     */
    private function transferring(int $docId, EpcisDocument $document): array
    {
        $session = TransferringSession::query()
            ->where('transfer_epcis_document_id', $docId)
            ->first(['id', 'from_site_id']);

        return [
            'kind' => EpcisJobKind::OutboundTransferring,
            'outbound_shipping_session_id' => null,
            'receiving_session_id' => null,
            'transferring_session_id' => $session?->id,
            'sscc_label_batch_id' => null,
            'ship_from_site_id' => $session?->from_site_id ?? $document->ship_from_site_id,
        ];
    }

    /**
     * @return array{
     *     kind: EpcisJobKind,
     *     outbound_shipping_session_id: ?int,
     *     receiving_session_id: ?int,
     *     transferring_session_id: ?int,
     *     sscc_label_batch_id: ?int,
     *     ship_from_site_id: ?int
     * }
     */
    private function sscc(int $docId, EpcisDocument $document, EpcisJobKind $kind): array
    {
        $batchId = null;

        if (preg_match('/sscc-batch-(\d+)/', (string) $document->original_filename, $m)
            || preg_match('/batch[:\s#]*(\d+)/i', (string) ($document->notes ?? ''), $m)
        ) {
            $batchId = (int) $m[1];
        }

        if ($batchId === null && Schema::hasColumn('sscc_label_batches', 'source_epcis_document_id')) {
            $found = DB::table('sscc_label_batches')
                ->where('source_epcis_document_id', $docId)
                ->value('id');
            $batchId = $found !== null ? (int) $found : null;
        }

        $batch = $batchId !== null
            ? SsccLabelBatch::query()->find($batchId, ['id', 'commission_site_id'])
            : null;

        return [
            'kind' => $kind,
            'outbound_shipping_session_id' => null,
            'receiving_session_id' => null,
            'transferring_session_id' => null,
            'sscc_label_batch_id' => $batch?->id,
            'ship_from_site_id' => $batch?->commission_site_id ?? $document->ship_from_site_id,
        ];
    }
}
