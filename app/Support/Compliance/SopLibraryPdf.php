<?php

declare(strict_types=1);

namespace App\Support\Compliance;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Dompdf render of all SOP library cards as a printable starter pack.
 */
final class SopLibraryPdf
{
    public function render(): string
    {
        $sections = '';

        foreach (SopLibraryCatalog::all() as $sop) {
            $title = e($sop['title']);
            $summary = e($sop['summary']);
            $steps = '';

            foreach ($sop['steps'] as $index => $step) {
                $steps .= '<li>'.e($step).'</li>';
            }

            $sections .= <<<HTML
<section class="sop">
<h2>{$title}</h2>
<p class="summary">{$summary}</p>
<ol>{$steps}</ol>
</section>
HTML;
        }

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
body { font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.45; color: #1c2430; }
h1 { font-size: 16px; margin: 0 0 16px; }
h2 { font-size: 14px; margin: 0 0 8px; }
.summary { margin: 0 0 8px; color: #4a5568; }
.sop { margin-bottom: 20px; page-break-inside: avoid; }
ol { margin: 0; padding-left: 18px; }
li { margin-bottom: 4px; }
</style>
</head>
<body>
<h1>TracePharma SOP starter pack</h1>
{$sections}
</body>
</html>
HTML;

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
