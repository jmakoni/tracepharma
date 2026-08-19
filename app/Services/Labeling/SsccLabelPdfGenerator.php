<?php

namespace App\Services\Labeling;

use App\Support\Gs1\Gs1Code128Svg;
use App\Support\Gs1\Sgln;
use App\Support\Shipping\ResolveShipFromSite;
use App\Support\TenantSettings;
use Dompdf\Dompdf;
use Dompdf\Options;
use Throwable;

class SsccLabelPdfGenerator
{
    public function __construct(
        private readonly ResolveShipFromSite $resolveShipFromSite,
    ) {}

    /**
     * @param  array{
     *     sscc_18: string,
     *     hrt: string,
     *     element_string: string,
     *     sscc_urn: string,
     *     ship_to_name?: ?string,
     *     ship_to_gln?: ?string,
     *     ship_from_name?: ?string,
     *     ship_from_gln?: ?string,
     *     notes?: ?string
     * }  $label
     */
    public function generate(array $label): string
    {
        $barcodeSvg = Gs1Code128Svg::forGs1ElementString($label['element_string']);
        $barcodeDataUri = 'data:image/svg+xml;base64,'.base64_encode($barcodeSvg);

        $shipFrom = $this->resolveShipFrom(
            $label['ship_from_name'] ?? null,
            $label['ship_from_gln'] ?? null,
        );

        $html = view('labeling.sscc-label', [
            'sscc18' => $label['sscc_18'],
            'hrt' => $label['hrt'],
            'ssccUrn' => $label['sscc_urn'],
            'barcodeDataUri' => $barcodeDataUri,
            'shipToName' => $label['ship_to_name'] ?? null,
            'shipToGln' => $this->formatGln($label['ship_to_gln'] ?? null),
            'shipFromName' => $shipFrom['name'],
            'shipFromGln' => $this->formatGln($shipFrom['gln']),
            'notes' => $label['notes'] ?? null,
            'generatedAt' => now()->format('Y-m-d H:i T'),
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper([0, 0, 288, 432], 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @return array{name: ?string, gln: ?string}
     */
    private function resolveShipFrom(?string $explicitName, ?string $explicitGln): array
    {
        if (($explicitName !== null && $explicitName !== '') || ($explicitGln !== null && $explicitGln !== '')) {
            return [
                'name' => $explicitName ?: tenant()?->name,
                'gln' => $explicitGln ?: TenantSettings::forTenant(tenant())->gln(),
            ];
        }

        try {
            $resolved = $this->resolveShipFromSite->locationGlnsForAuthoring();

            return [
                'name' => $resolved['site']->name ?? tenant()?->name,
                'gln' => $resolved['gln'],
            ];
        } catch (Throwable) {
            return [
                'name' => tenant()?->name,
                'gln' => TenantSettings::forTenant(tenant())->gln(),
            ];
        }
    }

    private function formatGln(?string $gln): ?string
    {
        if ($gln === null || $gln === '') {
            return null;
        }

        return Sgln::normalizeGln($gln) ?? preg_replace('/\D/', '', $gln) ?: $gln;
    }
}
