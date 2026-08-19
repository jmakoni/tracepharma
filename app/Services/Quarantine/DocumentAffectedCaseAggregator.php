<?php

namespace App\Services\Quarantine;

use App\Models\Epcis\EpcisDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per case-level SGTIN parent on a document, with child unit counts.
 */
final class DocumentAffectedCaseAggregator
{
    /**
     * @return Collection<int, object{
     *     parent_epc_id: int,
     *     gtin14: string|null,
     *     serial_number: string|null,
     *     product_id: int|null,
     *     product_name: string|null,
     *     package_ndc: string|null,
     *     ndc11: string|null,
     *     ndc: string|null,
     *     lot_number: string|null,
     *     expiry_date: string|null,
     *     child_count: int
     * }>
     */
    public function aggregate(EpcisDocument $document): Collection
    {
        $documentId = (int) $document->getKey();
        $generation = (int) ($document->ingest_generation ?? 1);

        if (! Schema::hasTable('aggregation_links') || ! Schema::hasTable('epcis_events')) {
            return collect();
        }

        $query = DB::table('aggregation_links as al')
            ->join('epcis_events as e', 'e.id', '=', 'al.established_by_event_id')
            ->join('epcs as p', 'p.id', '=', 'al.parent_epc_id')
            ->leftJoin('epc_ilmd as il', 'il.epc_id', '=', 'p.id')
            ->leftJoin('products', 'products.id', '=', 'p.product_id')
            ->where('e.document_id', $documentId)
            ->whereNull('al.valid_to')
            ->where('p.epc_type', 'sgtin');

        if (Schema::hasColumn('epcis_events', 'ingest_generation')) {
            $query->where('e.ingest_generation', $generation);
        }

        return $query
            ->groupBy([
                'p.id',
                'p.gtin14',
                'p.serial_number',
                'p.product_id',
                'products.name',
                'products.package_ndc',
                'products.ndc11',
                'products.ndc',
                'il.lot_number',
                'il.expiry_date',
            ])
            ->orderBy('p.gtin14')
            ->orderBy('p.serial_number')
            ->selectRaw('
                p.id as parent_epc_id,
                p.gtin14 as gtin14,
                p.serial_number as serial_number,
                p.product_id as product_id,
                products.name as product_name,
                products.package_ndc as package_ndc,
                products.ndc11 as ndc11,
                products.ndc as ndc,
                il.lot_number as lot_number,
                il.expiry_date as expiry_date,
                COUNT(al.child_epc_id) as child_count
            ')
            ->get();
    }
}
