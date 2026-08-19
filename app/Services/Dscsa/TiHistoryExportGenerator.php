<?php

namespace App\Services\Dscsa;

use App\Models\Epcis\EpcisDocument;
use App\Models\User;
use App\Services\Dscsa\Support\EpcisShipmentReportContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CSV TI/lot/serial listing for a parsed inbound EPCIS document.
 */
final class TiHistoryExportGenerator
{
    public function __construct(
        private readonly EpcisShipmentReportContext $context,
    ) {}

    /**
     * @return array{binary: string, filename: string, content_type: string}
     */
    public function generate(EpcisDocument $document, ?User $actor = null): array
    {
        $rows = $this->serialRows($document);
        $shipping = $this->context->resolveShippingContext($document);

        $lines = [];
        $lines[] = $this->csvLine([
            'gtin14',
            'serial_number',
            'lot',
            'expiry',
            'epc_uri',
            'seller',
            'buyer',
            'transaction_date',
            'document_id',
            'reference',
            'asn_number',
            'customer_po',
        ]);

        $seller = (string) ($shipping['seller_name'] ?? $document->ship_from_name ?? '');
        $buyer = $this->resolveBuyerName($document, $shipping);
        $transactionDate = (string) ($shipping['transaction_date'] ?? '');
        $reference = $this->context->referenceNumber($document);

        foreach ($rows as $row) {
            $lines[] = $this->csvLine([
                $row['gtin14'],
                $row['serial_number'],
                $row['lot'],
                $row['expiry'],
                $row['epc_uri'],
                $seller,
                $buyer,
                $transactionDate,
                (string) $document->getKey(),
                $reference,
                (string) ($document->asn_number ?? ''),
                (string) ($document->customer_po ?? ''),
            ]);
        }

        if ($rows === []) {
            $lines[] = $this->csvLine([
                '', '', '', '', '',
                $seller, $buyer, $transactionDate,
                (string) $document->getKey(),
                $reference,
                (string) ($document->asn_number ?? ''),
                (string) ($document->customer_po ?? ''),
            ]);
        }

        $ref = preg_replace('/[^A-Za-z0-9_-]+/', '_', $reference) ?: 'DOC';

        return [
            'binary' => implode("\n", $lines)."\n",
            'filename' => 'TI_History_'.$ref.'_'.now()->format('Ymd').'.csv',
            'content_type' => 'text/csv; charset=UTF-8',
        ];
    }

    /**
     * @return list<array{gtin14: string, serial_number: string, lot: string, expiry: string, epc_uri: string}>
     */
    private function serialRows(EpcisDocument $document): array
    {
        $documentId = (int) $document->getKey();
        $generation = (int) ($document->ingest_generation ?? 1);

        if (! Schema::hasTable('document_epcs') || ! Schema::hasTable('epcs')) {
            return [];
        }

        $query = DB::table('document_epcs as de')
            ->join('epcs', 'epcs.id', '=', 'de.epc_id')
            ->where('de.document_id', $documentId)
            ->where('de.ingest_generation', $generation)
            ->where('epcs.epc_type', 'sgtin')
            ->select([
                'epcs.id',
                'epcs.gtin14',
                'epcs.serial_number',
                'epcs.epc_uri',
            ]);

        if (Schema::hasTable('epc_ilmd')) {
            $query->leftJoin('epc_ilmd', 'epc_ilmd.epc_id', '=', 'epcs.id')
                ->addSelect([
                    'epc_ilmd.lot_number',
                    'epc_ilmd.expiry_date',
                ]);
        }

        return $query->orderBy('epcs.gtin14')->orderBy('epcs.serial_number')->get()
            ->map(fn (object $row): array => [
                'gtin14' => (string) ($row->gtin14 ?? ''),
                'serial_number' => (string) ($row->serial_number ?? ''),
                'lot' => (string) ($row->lot_number ?? ''),
                'expiry' => isset($row->expiry_date) && $row->expiry_date !== null ? (string) $row->expiry_date : '',
                'epc_uri' => (string) ($row->epc_uri ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{ownership_rows?: list<array{receiver?: string}>, seller_name?: string}  $shipping
     */
    private function resolveBuyerName(EpcisDocument $document, array $shipping): string
    {
        if (filled($document->ship_to_name)) {
            return (string) $document->ship_to_name;
        }

        $partnerName = $document->relationLoaded('shipToPartner')
            ? $document->shipToPartner?->name
            : $document->shipToPartner()->value('name');

        if (filled($partnerName)) {
            return (string) $partnerName;
        }

        $firstReceiver = $shipping['ownership_rows'][0]['receiver'] ?? null;
        if (is_string($firstReceiver) && trim($firstReceiver) !== '' && $firstReceiver !== '—') {
            $line = strtok($firstReceiver, "\n");

            return is_string($line) && $line !== '' ? $line : $firstReceiver;
        }

        return '';
    }

    /**
     * @param  list<string>  $fields
     */
    private function csvLine(array $fields): string
    {
        return collect($fields)
            ->map(function (string $field): string {
                if (preg_match('/^[=+\-@\t\r]/', $field) === 1) {
                    $field = "'".$field;
                }

                if (str_contains($field, ',') || str_contains($field, '"') || str_contains($field, "\n")) {
                    return '"'.str_replace('"', '""', $field).'"';
                }

                return $field;
            })
            ->implode(',');
    }
}
