<?php

namespace App\Actions\Fda3911;

use App\Models\Fda3911Report;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateFda3911Pdf
{
    public function execute(Fda3911Report $report): Fda3911Report
    {
        $report->loadMissing(['tradingPartner']);

        $html = view('reports.fda-3911', ['report' => $report])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        $tenantKey = (string) (tenant('id') ?? 'central');
        $path = "fda-3911/{$tenantKey}/".Str::uuid().'.pdf';
        $disk = config('filesystems.default', 'local');
        Storage::disk($disk)->put($path, $dompdf->output());

        $report->update(['generated_pdf_path' => $path]);

        return $report->refresh();
    }
}
