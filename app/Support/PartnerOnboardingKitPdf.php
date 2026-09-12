<?php

declare(strict_types=1);

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Dompdf render of the partner onboarding IT brief.
 */
final class PartnerOnboardingKitPdf
{
    public function render(): string
    {
        $brief = app(PartnerOnboardingKit::class)->exportBrief();
        $escaped = e($brief);
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
body { font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.45; color: #1c2430; }
h1 { font-size: 16px; margin: 0 0 12px; }
pre { white-space: pre-wrap; font-family: DejaVu Sans Mono, monospace; font-size: 11px; }
</style>
</head>
<body>
<h1>TracePharma partner onboarding</h1>
<pre>{$escaped}</pre>
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
