<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\View\View;

final class MarketingPdf
{
    /**
     * @return array{logoDataUri: string}
     */
    public static function sharedViewData(): array
    {
        return [
            'logoDataUri' => self::logoDataUri(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function render(string $view, array $data = [], string $paper = 'letter', string $orientation = 'portrait'): string
    {
        $html = view($view, array_merge(self::sharedViewData(), $data))->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper($paper, $orientation);
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function makeView(string $view, array $data = []): View
    {
        return view($view, array_merge(self::sharedViewData(), $data));
    }

    private static function logoDataUri(): string
    {
        $path = public_path('images/brand/logo.svg');

        if (! is_file($path)) {
            return '';
        }

        return 'data:image/svg+xml;base64,'.base64_encode((string) file_get_contents($path));
    }
}
