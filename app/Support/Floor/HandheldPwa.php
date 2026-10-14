<?php

namespace App\Support\Floor;

use Illuminate\Http\Request;

final class HandheldPwa
{
    public const PACKAGE_NAME = 'com.tracepharma.floor';

    /**
     * @return list<string>
     */
    public static function allowedHostSuffixes(): array
    {
        $suffixes = config('tracepharma.handheld.allowed_host_suffixes', [
            'tracepharma.io',
            'internal.vatengi.com',
        ]);

        return is_array($suffixes) ? array_values(array_filter($suffixes, is_string(...))) : [];
    }

    public static function isAllowedHost(string $host): bool
    {
        $host = strtolower(rtrim(trim($host), '.'));

        if ($host === '' || str_contains($host, '/') || str_contains($host, ' ') || str_contains($host, '@')) {
            return false;
        }

        if (! filter_var('http://'.$host, FILTER_VALIDATE_URL)) {
            return false;
        }

        if (in_array($host, ['localhost', '127.0.0.1'], true)) {
            return app()->environment(['local', 'testing']);
        }

        foreach (['.test', '.example', '.invalid', '.localhost'] as $reserved) {
            if ($host !== 'localhost' && str_ends_with($host, $reserved)) {
                return false;
            }
        }

        foreach (self::allowedHostSuffixes() as $suffix) {
            $suffix = strtolower($suffix);
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeHost(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (! str_contains($raw, '://')) {
            $raw = 'https://'.$raw;
        }

        $parts = parse_url($raw);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '' || ! self::isAllowedHost($host)) {
            return null;
        }

        return $host;
    }

    public static function floorLoginUrl(string $host): string
    {
        $scheme = in_array($host, ['localhost', '127.0.0.1'], true) ? 'http' : 'https';

        return $scheme.'://'.$host.'/login';
    }

    /**
     * @return array<string, mixed>
     */
    public static function manifest(Request $request): array
    {
        $origin = rtrim($request->getSchemeAndHttpHost(), '/');
        $onTenant = tenant() !== null;
        $startPath = $onTenant ? '/floor' : '/handheld';

        return [
            'id' => $origin.$startPath,
            'name' => 'TracePharma Floor',
            'short_name' => 'Floor',
            'description' => 'Warehouse floor receive, ship, transfer, pack, and verify.',
            'start_url' => $origin.$startPath,
            'scope' => $origin.'/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'theme_color' => (string) config('tracepharma.handheld.theme_color', '#51BC8F'),
            'background_color' => (string) config('tracepharma.handheld.background_color', '#0F172A'),
            'icons' => [
                [
                    'src' => $origin.'/images/brand/floor-icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => $origin.'/images/brand/floor-icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
        ];
    }

    /**
     * @return list<array{relation: list<string>, target: array<string, mixed>}>
     */
    public static function assetLinks(): array
    {
        $package = (string) config('tracepharma.handheld.package_name', self::PACKAGE_NAME);
        $fingerprints = config('tracepharma.handheld.sha256_cert_fingerprints', []);
        $fingerprints = is_array($fingerprints)
            ? array_values(array_filter($fingerprints, is_string(...)))
            : [];

        return [
            [
                'relation' => ['delegate_permission/common.handle_all_urls'],
                'target' => [
                    'namespace' => 'android_app',
                    'package_name' => $package !== '' ? $package : self::PACKAGE_NAME,
                    'sha256_cert_fingerprints' => $fingerprints,
                ],
            ],
        ];
    }
}
