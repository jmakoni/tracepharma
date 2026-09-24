<?php

declare(strict_types=1);

namespace App\Services\Dscsa\Support;

use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Derive outbound GS1 US purchase extensions from inbound shipping documents.
 *
 * @phpstan-type PurchaseExtensions array{
 *     statement: string,
 *     qualifier: string,
 *     indirect_uris: list<string>,
 *     prev_wholesaler_qualifier: ?string,
 *     prev_wholesaler_statement: ?string
 * }
 */
final class ResolveOutboundDscsaPurchaseExtensions
{
    public function __construct(
        private readonly DscsaDirectPurchaseStatements $statements,
    ) {}

    /**
     * @param  list<int>  $epcIds
     * @return PurchaseExtensions|null
     */
    public function handle(array $epcIds, bool $affirm, ?Tenant $tenant): ?array
    {
        $sellerName = filled($tenant?->name) ? (string) $tenant->name : 'Seller';
        $statement = $this->statements->outboundWholesalerDirectPurchaseStatement(
            $tenant,
            $affirm,
            $sellerName,
        );

        if ($statement === null) {
            return null;
        }

        $lookupIds = array_values(array_unique(array_filter(
            array_map(intval(...), $epcIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($lookupIds !== []) {
            $childIds = AggregationLink::query()
                ->open()
                ->whereIn('parent_epc_id', $lookupIds)
                ->pluck('child_epc_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $lookupIds = array_values(array_unique([...$lookupIds, ...$childIds]));
        }

        $shippedUris = $lookupIds === []
            ? []
            : Epc::query()
                ->whereIn('id', $lookupIds)
                ->pluck('epc_uri')
                ->map(static fn (mixed $uri): string => (string) $uri)
                ->all();

        $inbound = $this->inboundDocumentsForEpcs($lookupIds);
        $indirect = [];
        $prevQualifier = null;
        $prevStatement = null;

        foreach ($inbound as $document) {
            foreach ((array) $document->direct_purchase_indirect_epc_uris as $uri) {
                $uri = trim((string) $uri);
                if ($uri !== '' && in_array($uri, $shippedUris, true)) {
                    $indirect[] = $uri;
                }
            }

            if (filled($document->received_prev_wholesaler_qualifier)) {
                $prevQualifier = 'ENTIRELY_DIRECT';
                $prevStatement = filled($document->received_prev_wholesaler_statement)
                    ? (string) $document->received_prev_wholesaler_statement
                    : DscsaDirectPurchaseStatements::RECEIVED_PREV_WHOLESALER_DEFAULT;
            }
        }

        $indirect = array_values(array_unique($indirect));
        $qualifier = 'ENTIRELY_DIRECT';
        if ($indirect !== []) {
            $qualifier = $this->allShippedIndirect($shippedUris, $indirect)
                ? 'ENTIRELY_INDIRECT'
                : 'PARTIALLY_DIRECT';
        }

        return [
            'statement' => $statement,
            'qualifier' => $qualifier,
            'indirect_uris' => $qualifier === 'PARTIALLY_DIRECT' ? $indirect : [],
            'prev_wholesaler_qualifier' => $prevQualifier,
            'prev_wholesaler_statement' => $prevStatement,
        ];
    }

    /**
     * @param  list<int>  $epcIds
     * @return list<EpcisDocument>
     */
    private function inboundDocumentsForEpcs(array $epcIds): array
    {
        if ($epcIds === []) {
            return [];
        }

        $documentIds = DB::table('event_epcs')
            ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
            ->join('epcis_documents', 'epcis_documents.id', '=', 'epcis_events.document_id')
            ->whereIn('event_epcs.epc_id', $epcIds)
            ->where('epcis_documents.direction', 'inbound')
            ->distinct()
            ->pluck('epcis_documents.id')
            ->all();

        if ($documentIds === []) {
            return [];
        }

        return EpcisDocument::query()
            ->whereIn('id', $documentIds)
            ->get()
            ->all();
    }

    /**
     * @param  list<string>  $shippedUris
     * @param  list<string>  $indirect
     */
    private function allShippedIndirect(array $shippedUris, array $indirect): bool
    {
        if ($shippedUris === []) {
            return false;
        }

        foreach ($shippedUris as $uri) {
            if (! in_array($uri, $indirect, true)) {
                return false;
            }
        }

        return true;
    }
}
