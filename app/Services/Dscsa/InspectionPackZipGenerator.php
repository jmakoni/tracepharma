<?php

namespace App\Services\Dscsa;

use App\Models\AtpLicense;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Fda3911Report;
use App\Models\Quarantine\QuarantineHold;
use App\Support\Auth\SiteAccess;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * One-click inspection ZIP. Compliance reports hub stays unchanged.
 */
final class InspectionPackZipGenerator
{
    /**
     * @return array{binary: string, filename: string, content_type: string}
     */
    public function generate(?int $siteId = null): array
    {
        if ($siteId === null || $siteId < 1) {
            throw new \InvalidArgumentException('Select a site before downloading an inspection pack.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'tp-insp-');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create temporary inspection pack file.');
        }

        $zipPath = $tmp.'.zip';
        @unlink($tmp);

        $zip = new ZipArchive;

        try {
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Unable to open inspection pack ZIP.');
            }

            $zip->addFromString('manifest.json', (string) json_encode([
                'generated_at' => now()->toIso8601String(),
                'site_id' => $siteId,
                'contents' => ['atp-licenses.csv', 'exceptions-open.csv', 'quarantine-holds-open.csv', 'fda-3911'],
            ], JSON_PRETTY_PRINT));

            $zip->addFromString('atp-licenses.csv', $this->atpCsv($siteId));
            $zip->addFromString('exceptions-open.csv', $this->exceptionsCsv());
            $zip->addFromString('quarantine-holds-open.csv', $this->holdsCsv($siteId));
            $this->addLatest3911($zip);

            $zip->close();

            $binary = (string) file_get_contents($zipPath);
        } finally {
            @unlink($zipPath);
        }

        return [
            'binary' => $binary,
            'filename' => 'inspection-pack-'.now()->format('Ymd-His').'.zip',
            'content_type' => 'application/zip',
        ];
    }

    private function atpCsv(?int $siteId): string
    {
        $query = AtpLicense::query()->with('site:id,name,gln');
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }

        $rows = [['site', 'gln', 'license_number', 'state', 'expires', 'active']];
        foreach ($query->limit(500)->get() as $license) {
            $rows[] = [
                (string) ($license->site?->name ?? ''),
                (string) ($license->site?->gln ?? ''),
                (string) $license->license_number,
                (string) $license->license_state,
                $license->license_expiration_date?->toDateString() ?? '',
                $license->is_active ? 'yes' : 'no',
            ];
        }

        return $this->csv($rows);
    }

    private function exceptionsCsv(): string
    {
        $query = ExceptionCase::query()->open()->with('type:id,code');
        SiteAccess::constrainExceptionCases($query);

        $rows = [['id', 'title', 'type', 'status', 'created_at']];
        foreach ($query->latest('id')->limit(500)->get() as $case) {
            $rows[] = [
                (string) $case->getKey(),
                (string) $case->title,
                (string) ($case->type?->code ?? ''),
                (string) ($case->status?->value ?? $case->status),
                optional($case->created_at)->toIso8601String() ?? '',
            ];
        }

        return $this->csv($rows);
    }

    private function holdsCsv(?int $siteId = null): string
    {
        $query = QuarantineHold::query()->open();
        SiteAccess::constrainExceptionCaseRelation($query, 'exception');
        if ($siteId !== null) {
            $query->whereHas('exception', fn ($exception) => $exception->where('site_id', $siteId));
        }
        $rows = [['id', 'epc_id', 'exception_id', 'status']];
        foreach ($query->latest('id')->limit(500)->get() as $hold) {
            $rows[] = [
                (string) $hold->getKey(),
                (string) $hold->epc_id,
                (string) $hold->exception_id,
                (string) $hold->status,
            ];
        }

        return $this->csv($rows);
    }

    private function addLatest3911(ZipArchive $zip): void
    {
        $report = Fda3911Report::query()->latest('id')->first();
        if ($report === null || blank($report->generated_pdf_path)) {
            $zip->addFromString('fda-3911/README.txt', "No generated FDA 3911 PDF is on file.\n");

            return;
        }

        $disk = (string) config('filesystems.default', 'local');
        if (! Storage::disk($disk)->exists((string) $report->generated_pdf_path)) {
            $zip->addFromString('fda-3911/README.txt', "FDA 3911 PDF path is missing on disk.\n");

            return;
        }

        $zip->addFromString(
            'fda-3911/'.$report->getKey().'.pdf',
            (string) Storage::disk($disk)->get((string) $report->generated_pdf_path),
        );
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }
}
