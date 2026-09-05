<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DataExportStatus;
use App\Models\DataExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DataExportDownloadController extends Controller
{
    public function __invoke(Request $request, DataExport $export): StreamedResponse
    {
        if ($export->status !== DataExportStatus::Completed || $export->isExpired()) {
            abort(410, 'This export is no longer available.');
        }

        $disk = (string) ($export->storage_disk ?? '');
        $path = (string) ($export->storage_path ?? '');

        $this->assertSafeExportStorage($export, $disk, $path);

        if ($disk === '' || $path === '' || ! Storage::disk($disk)->exists($path)) {
            abort(404, 'Export file not found.');
        }

        $filename = 'DSCSA_Compliance_Report_'.$export->getKey().'.pdf';

        return Storage::disk($disk)->download($path, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function assertSafeExportStorage(DataExport $export, string $disk, string $path): void
    {
        $allowedDisk = (string) config('tracepharma.exports.disk', 'tenant_exports');

        if ($disk !== $allowedDisk || $path === '' || $path !== $export->storageObjectKey()) {
            abort(404, 'Export file not found.');
        }

        if (str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/')) {
            abort(404, 'Export file not found.');
        }
    }
}
