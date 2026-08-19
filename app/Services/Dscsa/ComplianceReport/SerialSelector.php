<?php

namespace App\Services\Dscsa\ComplianceReport;

use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\EpcisDocument;
use App\Services\Dscsa\Support\EpcisShipmentReportContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SerialSelector
{
    public function __construct(
        private readonly EpcisShipmentReportContext $context,
    ) {}

    /**
     * @param  list<int>  $lotSgtinIds
     * @return list<SerialRow>
     */
    public function forLot(EpcisDocument $document, string $lot, array $lotSgtinIds): array
    {
        if ($lotSgtinIds === [] || ! Schema::hasTable('epcs')) {
            return [];
        }

        $childIds = [];
        $parentChildCounts = [];

        if (Schema::hasTable('aggregation_links')) {
            $links = AggregationLink::query()
                ->open()
                ->forDocumentProjection($document)
                ->where(function ($q) use ($lotSgtinIds): void {
                    $q->whereIn('parent_epc_id', $lotSgtinIds)
                        ->orWhereIn('child_epc_id', $lotSgtinIds);
                })
                ->get(['parent_epc_id', 'child_epc_id']);

            foreach ($links as $link) {
                $parentId = (int) $link->parent_epc_id;
                $childId = (int) $link->child_epc_id;
                $childIds[$childId] = true;
                $parentChildCounts[$parentId] = ($parentChildCounts[$parentId] ?? 0) + 1;
            }
        }

        $includeIds = [];
        foreach ($lotSgtinIds as $epcId) {
            $isChild = isset($childIds[$epcId]);
            $isParent = array_key_exists($epcId, $parentChildCounts);
            $childCount = (int) ($parentChildCounts[$epcId] ?? 0);

            if ($isChild) {
                $includeIds[] = $epcId;

                continue;
            }

            if ($isParent && $childCount === 0) {
                $includeIds[] = $epcId;

                continue;
            }

            if ($isParent && $childCount > 0) {
                continue;
            }

            // Orphan SGTIN (neither parent nor child in projection).
            $includeIds[] = $epcId;
        }

        $includeIds = array_values(array_unique($includeIds));
        if ($includeIds === []) {
            return [];
        }

        $rows = DB::table('epcs')
            ->leftJoin('epc_ilmd', 'epc_ilmd.epc_id', '=', 'epcs.id')
            ->whereIn('epcs.id', $includeIds)
            ->where('epcs.epc_type', 'sgtin')
            ->orderBy('epcs.gtin14')
            ->orderBy('epcs.serial_number')
            ->get([
                'epcs.gtin14',
                'epcs.serial_number',
                'epc_ilmd.lot_number',
                'epc_ilmd.expiry_date',
            ]);

        $serials = [];
        foreach ($rows as $row) {
            $serials[] = new SerialRow(
                gtin: $this->context->display($row->gtin14 !== null ? (string) $row->gtin14 : null),
                serialNumber: $this->context->display($row->serial_number !== null ? (string) $row->serial_number : null),
                lot: filled($row->lot_number) ? (string) $row->lot_number : $lot,
                expirationDate: $this->context->formatDate($row->expiry_date),
            );
        }

        return $serials;
    }
}
