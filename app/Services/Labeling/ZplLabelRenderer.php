<?php

namespace App\Services\Labeling;

class ZplLabelRenderer
{
    /**
     * @param  array{
     *     sscc_18: string,
     *     hrt: string,
     *     ship_to_name?: ?string,
     *     ship_from_name?: ?string,
     *     copies?: int
     * }  $label
     */
    public function render(array $label): string
    {
        $sscc18 = preg_replace('/\D/', '', $label['sscc_18']) ?? '';
        $hrt = $this->escapeZpl((string) ($label['hrt'] ?? ''));
        $shipTo = $this->escapeZpl((string) ($label['ship_to_name'] ?? ''));
        $shipFrom = $this->escapeZpl((string) ($label['ship_from_name'] ?? ''));
        $copies = max(1, (int) ($label['copies'] ?? 1));

        $barcodeData = '00'.$sscc18;

        return <<<ZPL
^XA
^PW812
^LL1218
^FO40,40^A0N,28,28^FDPallet / Shipper (SSCC)^FS
^FO40,80^A0N,22,22^FDFrom: {$shipFrom}^FS
^FO40,110^A0N,22,22^FDTo: {$shipTo}^FS
^FO40,170^BCN,120,Y,N,N
^FD>;>8{$barcodeData}^FS
^FO40,320^A0N,30,30^FD{$hrt}^FS
^FO40,370^A0N,20,20^FDSSCC-18: {$sscc18}^FS
^PQ{$copies},0,1,Y
^XZ
ZPL;
    }

    private function escapeZpl(string $value): string
    {
        return str_replace(['^', '~', '\\'], '', $value);
    }
}
