<?php

declare(strict_types=1);

namespace App\Services\Tenants;

use App\Models\Epcis\EpcisDocument;
use App\Models\Product;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use Spatie\Activitylog\Models\Activity;
use ZipArchive;

/**
 * Bounded tenant compliance archive for offboarding / DSCSA retention posture.
 *
 * Activity log: last 90 days, capped at 50,000 most recent rows.
 * EPCIS: index metadata only (no full XML payloads).
 */
final class TenantComplianceArchiveGenerator
{
    public const ACTIVITY_LOG_DAYS = 90;

    public const ACTIVITY_LOG_MAX_ROWS = 50_000;

    /**
     * @return array{binary: string, manifest: array<string, mixed>}
     */
    public function generate(Tenant $tenant, int $adminId): array
    {
        $manifest = [
            'tenant_id' => $tenant->getKey(),
            'tenant_pair_slug' => $tenant->tenant_pair_slug,
            'profile' => $tenant->profile?->value ?? (string) $tenant->profile,
            'exported_at' => now()->toIso8601String(),
            'exported_by_admin_id' => $adminId,
            'activity_log' => [
                'days' => self::ACTIVITY_LOG_DAYS,
                'max_rows' => self::ACTIVITY_LOG_MAX_ROWS,
                'policy' => 'Most recent rows within the last '.self::ACTIVITY_LOG_DAYS.' days, capped at '.self::ACTIVITY_LOG_MAX_ROWS.'.',
            ],
            'epcis_documents_index' => [
                'policy' => 'Metadata index only; full EPCIS XML payloads are not included.',
            ],
        ];

        $files = [
            'manifest.json' => (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'activity_log.csv' => $this->activityLogCsv(),
            'products.csv' => $this->productsCsv(),
            'trading_partners.csv' => $this->tradingPartnersCsv(),
            'sites.csv' => $this->sitesCsv(),
            'epcis_documents_index.csv' => $this->epcisDocumentsIndexCsv(),
        ];

        $binary = $this->zipFromStrings($files);

        return [
            'binary' => $binary,
            'manifest' => $manifest,
        ];
    }

    /**
     * @param  array<string, string>  $files
     */
    private function zipFromStrings(array $files): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tp-compliance-');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create temporary compliance export file.');
        }

        $zipPath = $tmp.'.zip';
        @unlink($tmp);

        $zip = new ZipArchive;

        try {
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Unable to open compliance export ZIP.');
            }

            foreach ($files as $name => $contents) {
                $zip->addFromString($name, $contents);
            }

            if (! $zip->close()) {
                throw new \RuntimeException('Unable to finalize compliance export ZIP.');
            }

            $binary = file_get_contents($zipPath);
            if ($binary === false || $binary === '') {
                throw new \RuntimeException('Unable to read compliance export ZIP.');
            }

            return $binary;
        } finally {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
        }
    }

    private function activityLogCsv(): string
    {
        $headers = [
            'id',
            'log_name',
            'description',
            'event',
            'subject_type',
            'subject_id',
            'causer_type',
            'causer_id',
            'created_at',
        ];

        $rows = Activity::query()
            ->where('created_at', '>=', now()->subDays(self::ACTIVITY_LOG_DAYS))
            ->orderByDesc('id')
            ->limit(self::ACTIVITY_LOG_MAX_ROWS)
            ->get([
                'id',
                'log_name',
                'description',
                'event',
                'subject_type',
                'subject_id',
                'causer_type',
                'causer_id',
                'created_at',
            ]);

        return $this->csvString($headers, $rows->map(static fn (Activity $row): array => [
            $row->id,
            $row->log_name,
            $row->description,
            $row->event,
            $row->subject_type,
            $row->subject_id,
            $row->causer_type,
            $row->causer_id,
            $row->created_at?->toIso8601String(),
        ]));
    }

    private function productsCsv(): string
    {
        $headers = ['id', 'gtin', 'name', 'ndc', 'ndc11', 'dosage_form', 'strength', 'is_active'];

        $rows = Product::query()
            ->orderBy('id')
            ->get(['id', 'gtin', 'name', 'ndc', 'ndc11', 'dosage_form', 'strength', 'is_active']);

        return $this->csvString($headers, $rows->map(static fn (Product $row): array => [
            $row->id,
            $row->gtin,
            $row->name,
            $row->ndc,
            $row->ndc11,
            $row->dosage_form,
            $row->strength,
            $row->is_active ? '1' : '0',
        ]));
    }

    private function tradingPartnersCsv(): string
    {
        $headers = ['id', 'name', 'gln', 'partner_type', 'is_active'];

        $rows = TradingPartner::query()
            ->orderBy('id')
            ->get(['id', 'name', 'gln', 'partner_type', 'is_active']);

        return $this->csvString($headers, $rows->map(static fn (TradingPartner $row): array => [
            $row->id,
            $row->name,
            $row->gln,
            $row->partner_type?->value ?? (string) $row->partner_type,
            $row->is_active ? '1' : '0',
        ]));
    }

    private function sitesCsv(): string
    {
        $headers = [
            'id',
            'name',
            'code',
            'gln',
            'sgln',
            'trading_partner_id',
            'is_active',
            'is_organization_facility',
        ];

        $rows = Site::query()
            ->orderBy('id')
            ->get([
                'id',
                'name',
                'code',
                'gln',
                'sgln',
                'trading_partner_id',
                'is_active',
                'is_organization_facility',
            ]);

        return $this->csvString($headers, $rows->map(static fn (Site $row): array => [
            $row->id,
            $row->name,
            $row->code,
            $row->gln,
            $row->sgln,
            $row->trading_partner_id,
            $row->is_active ? '1' : '0',
            $row->is_organization_facility ? '1' : '0',
        ]));
    }

    private function epcisDocumentsIndexCsv(): string
    {
        $headers = ['id', 'document_uuid', 'type', 'status', 'created_at'];

        $rows = EpcisDocument::query()
            ->orderBy('id')
            ->get(['id', 'document_uuid', 'direction', 'authored_kind', 'status', 'created_at']);

        return $this->csvString($headers, $rows->map(static function (EpcisDocument $row): array {
            $type = $row->direction === 'outbound' && $row->authored_kind !== null
                ? (string) ($row->authored_kind->value ?? $row->authored_kind)
                : (string) $row->direction;

            return [
                $row->id,
                $row->document_uuid,
                $type,
                $row->status,
                $row->created_at?->toIso8601String(),
            ];
        }));
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<mixed>>  $rows
     */
    private function csvString(array $headers, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to create CSV buffer.');
        }

        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv !== false ? $csv : '';
    }
}
