<?php

declare(strict_types=1);

namespace App\Services\Dscsa\Support;

use App\Actions\Epcis\ResolveGlnToMasterData;
use App\Models\TradingPartner;
use Illuminate\Support\Facades\Http;

final class ComplianceReportBranding
{
    public function __construct(
        private readonly ResolveGlnToMasterData $resolveGln,
    ) {}

    /**
     * @return array{logoDataUri: ?string, sellerDisplayName: string}
     */
    public function resolve(?string $sellerGln, string $sellerName): array
    {
        $logoDataUri = null;

        if ($sellerGln !== null) {
            $master = $this->resolveGln->handle($sellerGln);
            $partner = $master['trading_partner'] ?? null;
            if ($partner instanceof TradingPartner) {
                $partner->loadMissing('fdaOrganization');
                $logoDataUri = $this->logoDataUriFromPartner($partner);
            }
        }

        if ($logoDataUri === null) {
            $logoDataUri = $this->tracePharmaLogoDataUri();
        }

        return [
            'logoDataUri' => $logoDataUri !== '' ? $logoDataUri : null,
            'sellerDisplayName' => $sellerName !== '—' && $sellerName !== '' ? $sellerName : 'Seller',
        ];
    }

    private function logoDataUriFromPartner(TradingPartner $partner): ?string
    {
        $candidates = array_filter([
            $partner->logo,
            $partner->fdaOrganization?->logo,
        ]);

        foreach ($candidates as $url) {
            $dataUri = $this->fetchLogoDataUri((string) $url);
            if ($dataUri !== null) {
                return $dataUri;
            }
        }

        return null;
    }

    private function fetchLogoDataUri(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, 'data:')) {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            $path = public_path(ltrim($url, '/'));
            if (is_file($path)) {
                return $this->fileToDataUri($path);
            }
        }

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $path = public_path($url);
            if (is_file($path)) {
                return $this->fileToDataUri($path);
            }

            return null;
        }

        try {
            $response = Http::timeout(5)->get($url);
            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();
            if ($body === '') {
                return null;
            }

            $mime = $response->header('Content-Type') ?: $this->guessMimeFromPath($url);

            return 'data:'.($mime ?: 'image/png').';base64,'.base64_encode($body);
        } catch (\Throwable) {
            return null;
        }
    }

    private function fileToDataUri(string $path): ?string
    {
        if (! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            return null;
        }

        $mime = $this->guessMimeFromPath($path) ?? 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    private function guessMimeFromPath(string $path): ?string
    {
        $extension = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?? $path, PATHINFO_EXTENSION));

        return match ($extension) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => null,
        };
    }

    private function tracePharmaLogoDataUri(): string
    {
        $path = public_path('images/brand/logo.svg');
        if (! is_file($path)) {
            return '';
        }

        return 'data:image/svg+xml;base64,'.base64_encode((string) file_get_contents($path));
    }
}
