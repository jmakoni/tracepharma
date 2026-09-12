<?php

declare(strict_types=1);

namespace App\Support\Portal\Exports;

use App\Models\Epcis\EpcisDocument;
use App\Models\PortalPublication;
use App\Support\Portal\PortalShipmentDisplay;
use Illuminate\Support\Carbon;

final class PortalShipmentExportColumns
{
    /**
     * @return array<string, string>
     */
    public static function summaryHeaders(): array
    {
        return [
            'published_at' => 'Published At',
            'customer_po' => 'Customer PO',
            'asn_number' => 'ASN Number',
            'shipment_reference' => 'Shipment Reference',
            'document_uuid' => 'Document UUID',
            'supplier_name' => 'Supplier Name',
            'document_status' => 'Document Status',
            'event_count' => 'Event Count',
            'epc_count' => 'EPC Count',
            'shipment_created_at' => 'Shipment Created At',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function lineHeaders(): array
    {
        return [
            'customer_po' => 'Customer PO',
            'asn_number' => 'ASN Number',
            'shipment_date' => 'Shipment Date',
            'supplier_name' => 'Supplier Name',
            'gtin14' => 'GTIN-14',
            'serial_number' => 'Serial Number',
            'lot_number' => 'Lot Number',
            'expiry_date' => 'Expiry Date',
            'quantity' => 'Quantity',
            'biz_step' => 'Biz Step',
            'disposition' => 'Disposition',
            'epc_type' => 'EPC Type',
            'epc_uri' => 'EPC URI',
            'document_uuid' => 'Document UUID',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function mapSummaryRow(PortalPublication $publication): array
    {
        $document = $publication->document;
        $createdAt = $document?->creation_date ?? $document?->created_at;

        return [
            'published_at' => self::formatDateTime($publication->published_at),
            'customer_po' => (string) ($document?->customer_po ?? ''),
            'asn_number' => (string) ($document?->asn_number ?? ''),
            'shipment_reference' => $document instanceof EpcisDocument
                ? PortalShipmentDisplay::label($document)
                : '',
            'document_uuid' => (string) ($document?->document_uuid ?? ''),
            'supplier_name' => (string) ($publication->tradingPartner?->name ?? ''),
            'document_status' => (string) ($document?->status ?? ''),
            'event_count' => (string) ($document?->event_count ?? ''),
            'epc_count' => (string) ($document?->epc_count ?? ''),
            'shipment_created_at' => self::formatDateTime($createdAt),
        ];
    }

    /**
     * @param  object{
     *     customer_po?: ?string,
     *     asn_number?: ?string,
     *     shipment_date?: mixed,
     *     supplier_name?: ?string,
     *     gtin14?: ?string,
     *     serial_number?: ?string,
     *     lot_number?: ?string,
     *     expiry_date?: mixed,
     *     biz_step?: ?string,
     *     disposition?: ?string,
     *     epc_type?: ?string,
     *     epc_uri?: ?string,
     *     document_uuid?: ?string,
     * }  $row
     * @return array<string, string>
     */
    public static function mapLineRow(object $row): array
    {
        return [
            'customer_po' => (string) ($row->customer_po ?? ''),
            'asn_number' => (string) ($row->asn_number ?? ''),
            'shipment_date' => self::formatDateTime($row->shipment_date ?? null),
            'supplier_name' => (string) ($row->supplier_name ?? ''),
            'gtin14' => (string) ($row->gtin14 ?? ''),
            'serial_number' => (string) ($row->serial_number ?? ''),
            'lot_number' => (string) ($row->lot_number ?? ''),
            'expiry_date' => self::formatDate($row->expiry_date ?? null),
            'quantity' => '1',
            'biz_step' => self::humanizeCbv($row->biz_step ?? null),
            'disposition' => self::humanizeCbv($row->disposition ?? null),
            'epc_type' => (string) ($row->epc_type ?? ''),
            'epc_uri' => (string) ($row->epc_uri ?? ''),
            'document_uuid' => (string) ($row->document_uuid ?? ''),
        ];
    }

    public static function humanizeCbv(mixed $uri): string
    {
        if (! is_string($uri) || ! filled($uri)) {
            return '';
        }

        $short = str_replace(
            ['urn:epcglobal:cbv:bizstep:', 'https://ref.gs1.org/cbv/BizStep-', 'urn:epcglobal:cbv:disp:', 'https://ref.gs1.org/cbv/Disp-'],
            '',
            $uri,
        );

        return class_basename($short);
    }

    private static function formatDateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof Carbon) {
            return $value->timezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
        }

        try {
            return Carbon::parse($value)->timezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private static function formatDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
