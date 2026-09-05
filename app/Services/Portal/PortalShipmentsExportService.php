<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Enums\Portal\PortalShipmentExportFormat;
use App\Enums\Portal\PortalShipmentExportGrain;
use App\Models\PortalPublication;
use App\Support\Portal\Exports\PortalShipmentExportColumns;
use DomainException;
use Dompdf\Dompdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PortalShipmentsExportService
{
    /**
     * @param  Collection<int, PortalPublication>  $publications
     */
    public function download(
        Collection $publications,
        PortalShipmentExportGrain $grain,
        PortalShipmentExportFormat $format,
    ): StreamedResponse {
        $headers = $grain === PortalShipmentExportGrain::Summary
            ? PortalShipmentExportColumns::summaryHeaders()
            : PortalShipmentExportColumns::lineHeaders();

        $rows = $grain === PortalShipmentExportGrain::Summary
            ? $this->buildSummaryRows($publications)
            : $this->buildLineRows($publications);

        $this->assertWithinRowLimit($rows, $format);

        $fileName = $this->fileName($grain);

        return match ($format) {
            PortalShipmentExportFormat::Csv => $this->downloadCsv($fileName, $headers, $rows),
            PortalShipmentExportFormat::Xlsx => $this->downloadXlsx($fileName, $headers, $rows),
            PortalShipmentExportFormat::Json => $this->downloadJson($fileName, $headers, $rows),
            PortalShipmentExportFormat::Xml => $this->downloadXml($fileName, $headers, $rows),
            PortalShipmentExportFormat::Pdf => $this->downloadPdf($fileName, $headers, $rows, $grain),
        };
    }

    /**
     * @param  Collection<int, PortalPublication>  $publications
     * @return list<array<string, string>>
     */
    private function buildSummaryRows(Collection $publications): array
    {
        return $publications
            ->map(static fn (PortalPublication $publication): array => PortalShipmentExportColumns::mapSummaryRow($publication))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, PortalPublication>  $publications
     * @return list<array<string, string>>
     */
    private function buildLineRows(Collection $publications): array
    {
        $documentIds = $publications
            ->pluck('epcis_document_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($documentIds === []) {
            return [];
        }

        $publicationsByDocument = $publications->keyBy(static fn (PortalPublication $p): int => (int) $p->epcis_document_id);

        $query = DB::table('epcis_events as ee')
            ->join('event_epcs as evp', 'evp.event_id', '=', 'ee.id')
            ->join('epcs as e', 'e.id', '=', 'evp.epc_id')
            ->join('epcis_documents as d', 'd.id', '=', 'ee.document_id')
            ->leftJoin('epc_ilmd as ilmd', 'ilmd.epc_id', '=', 'e.id')
            ->leftJoin('trading_partners as tp', 'tp.id', '=', 'd.trading_partner_id')
            ->whereIn('ee.document_id', $documentIds)
            ->where('e.epc_type', 'sgtin')
            ->select([
                'ee.document_id',
                'ee.event_time',
                'ee.biz_step',
                'ee.disposition',
                'd.customer_po',
                'd.asn_number',
                'd.document_uuid',
                'tp.name as supplier_name',
                'e.gtin14',
                'e.serial_number',
                'e.epc_type',
                'e.epc_uri',
                'ilmd.lot_number',
                'ilmd.expiry_date',
            ])
            ->orderBy('ee.event_time')
            ->orderBy('e.id');

        if (Schema::hasColumn('epcis_events', 'superseded_at')) {
            $query->whereNull('ee.superseded_at');
        }

        $rows = [];

        foreach ($query->cursor() as $row) {
            $publication = $publicationsByDocument->get((int) $row->document_id);
            $shipmentDate = $row->event_time ?? $publication?->published_at;

            $rows[] = PortalShipmentExportColumns::mapLineRow((object) [
                'customer_po' => $row->customer_po,
                'asn_number' => $row->asn_number,
                'shipment_date' => $shipmentDate,
                'supplier_name' => $row->supplier_name,
                'gtin14' => $row->gtin14,
                'serial_number' => $row->serial_number,
                'lot_number' => $row->lot_number,
                'expiry_date' => $row->expiry_date,
                'biz_step' => $row->biz_step,
                'disposition' => $row->disposition,
                'epc_type' => $row->epc_type,
                'epc_uri' => $row->epc_uri,
                'document_uuid' => $row->document_uuid,
            ]);
        }

        return $rows;
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function assertWithinRowLimit(array $rows, PortalShipmentExportFormat $format): void
    {
        $limit = $format === PortalShipmentExportFormat::Pdf
            ? max(1, (int) config('advanced-table-export-for-filament.max_pdf_rows', 200))
            : max(1, (int) config('advanced-table-export-for-filament.max_export_rows', 2000));

        $count = count($rows);

        if ($count > $limit) {
            throw new DomainException(
                "Export would return {$count} rows, which exceeds the limit of {$limit}. Narrow your filters or export fewer shipments.",
            );
        }
    }

    private function fileName(PortalShipmentExportGrain $grain): string
    {
        $prefix = $grain === PortalShipmentExportGrain::Summary
            ? 'portal-shipments'
            : 'portal-pms-intake';

        $timestamp = now()->format((string) config('advanced-table-export-for-filament.time_format', 'M_d_Y-H_i'));

        return Str::slug($prefix.'-'.$timestamp);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<array<string, string>>  $rows
     */
    private function downloadCsv(string $fileName, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $options = new CsvOptions;
            $options->FIELD_DELIMITER = (string) config('advanced-table-export-for-filament.csv_delimiter', ',');

            $writer = new CsvWriter($options);
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(array_values($headers)));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($this->orderedValues($headers, $row)));
            }

            $writer->close();
        }, $fileName.'.csv', [
            'Content-Type' => PortalShipmentExportFormat::Csv->contentType(),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<array<string, string>>  $rows
     */
    private function downloadXlsx(string $fileName, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $writer = new XlsxWriter;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(array_values($headers)));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($this->orderedValues($headers, $row)));
            }

            $writer->close();
        }, $fileName.'.xlsx', [
            'Content-Type' => PortalShipmentExportFormat::Xlsx->contentType(),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<array<string, string>>  $rows
     */
    private function downloadJson(string $fileName, array $headers, array $rows): StreamedResponse
    {
        $payload = array_map(
            static function (array $row) use ($headers): array {
                $mapped = [];

                foreach ($headers as $key => $label) {
                    $mapped[$label] = $row[$key] ?? '';
                }

                return $mapped;
            },
            $rows,
        );

        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE;

        if ((bool) config('advanced-table-export-for-filament.pretty_json', true)) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $content = json_encode($payload, $flags);

        return response()->streamDownload(
            static function () use ($content): void {
                echo $content;
            },
            $fileName.'.json',
            [
                'Content-Type' => PortalShipmentExportFormat::Json->contentType(),
                'Cache-Control' => 'no-store, private',
            ],
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<array<string, string>>  $rows
     */
    private function downloadXml(string $fileName, array $headers, array $rows): StreamedResponse
    {
        $root = (string) config('advanced-table-export-for-filament.xml_root', 'rows');
        $rowTag = (string) config('advanced-table-export-for-filament.xml_row_tag', 'row');
        $content = $this->buildXml($root, $rowTag, $headers, $rows);

        return response()->streamDownload(
            static function () use ($content): void {
                echo $content;
            },
            $fileName.'.xml',
            [
                'Content-Type' => PortalShipmentExportFormat::Xml->contentType(),
                'Cache-Control' => 'no-store, private',
            ],
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<array<string, string>>  $rows
     */
    private function downloadPdf(
        string $fileName,
        array $headers,
        array $rows,
        PortalShipmentExportGrain $grain,
    ): StreamedResponse {
        $title = $grain === PortalShipmentExportGrain::Summary
            ? 'Portal Shipments Export'
            : 'Portal PMS Intake Export';

        $html = view('client-portal.exports.table-pdf', [
            'title' => $title,
            'headers' => $headers,
            'rows' => $rows,
        ])->render();

        $dompdf = new Dompdf;
        $dompdf->loadHtml($html);
        $dompdf->setPaper(
            'A4',
            (string) config('advanced-table-export-for-filament.default_page_orientation', 'landscape'),
        );
        $dompdf->render();

        $binary = $dompdf->output();

        return response()->streamDownload(
            static function () use ($binary): void {
                echo $binary;
            },
            $fileName.'.pdf',
            [
                'Content-Type' => PortalShipmentExportFormat::Pdf->contentType(),
                'Cache-Control' => 'no-store, private',
            ],
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function orderedValues(array $headers, array $row): array
    {
        $values = [];

        foreach (array_keys($headers) as $key) {
            $values[] = $row[$key] ?? '';
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<array<string, string>>  $rows
     */
    private function buildXml(string $root, string $rowTag, array $headers, array $rows): string
    {
        $xml = new \XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement($this->sanitizeXmlTag($root, 'rows'));

        foreach ($rows as $row) {
            $xml->startElement($this->sanitizeXmlTag($rowTag, 'row'));

            foreach ($headers as $columnName => $label) {
                $xml->writeElement($this->sanitizeXmlTag($columnName, 'column'), $row[$columnName] ?? '');
            }

            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function sanitizeXmlTag(string $value, string $fallback): string
    {
        $tag = preg_replace('/[^a-zA-Z0-9_-]/', '_', $value) ?? '';
        $tag = trim($tag, '_');

        if ($tag === '') {
            return $fallback;
        }

        if (preg_match('/^[0-9]/', $tag)) {
            $tag = '_'.$tag;
        }

        return $tag;
    }
}
