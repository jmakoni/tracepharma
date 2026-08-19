<?php

namespace App\Actions\Labeling;

use App\Models\SsccLabel;
use App\Services\Labeling\SsccLabelPdfGenerator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Ensure a label PDF exists on disk, regenerating when the DB path is orphaned
 * (e.g. batch committed before a failed Storage::put).
 */
final class EnsureSsccLabelPdf
{
    public function __construct(
        private readonly SsccLabelPdfGenerator $pdfGenerator,
    ) {}

    public function handle(SsccLabel $label): SsccLabel
    {
        if (blank($label->label_disk)) {
            $label->forceFill(['label_disk' => 'local'])->save();
        }

        if (blank($label->label_path)) {
            $label->forceFill([
                'label_path' => 'labels/sscc/'.$label->sscc_18.'-'.Str::uuid().'.pdf',
            ])->save();
        }

        $disk = Storage::disk((string) $label->label_disk);

        if ($disk->exists((string) $label->label_path)) {
            return $label;
        }

        try {
            if (! $disk->directoryExists('labels/sscc')) {
                $disk->makeDirectory('labels/sscc');
            }

            $pdf = $this->pdfGenerator->generate([
                'sscc_18' => $label->sscc_18,
                'hrt' => $label->hrt,
                'element_string' => $label->element_string,
                'sscc_urn' => $label->sscc_urn,
                'ship_to_name' => $label->ship_to_name,
                'ship_to_gln' => $label->ship_to_gln,
                'notes' => $label->notes,
            ]);

            $disk->put((string) $label->label_path, $pdf);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'SSCC label PDF is missing and could not be regenerated. '.
                'Run `php artisan tracepharma:ensure-tenant-storage` if storage permissions are wrong. '.
                $exception->getMessage(),
                previous: $exception,
            );
        }

        return $label->refresh();
    }
}
