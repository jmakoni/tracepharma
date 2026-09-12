<?php

declare(strict_types=1);

namespace App\Services\Atp;

use App\Support\Epcis\EpcisSubscriptionUrl;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HTTP adapter for an external OCI-compatible wallet (Generate VP / Verify VP).
 * Does not mint keys or host a wallet.
 */
class OciWalletClient
{
    /**
     * @param  array{base_url: string, api_key: ?string, verify_path: string, present_path: string, timeout: int}  $config
     */
    public function __construct(
        private readonly array $config,
    ) {}

    public static function fromTenantSettings(?TenantSettings $settings = null): self
    {
        $settings ??= function_exists('tenant') && tenant()
            ? TenantSettings::forTenant(tenant())
            : null;

        return new self(self::resolveConfig($settings));
    }

    /**
     * @return array{base_url: string, api_key: ?string, verify_path: string, present_path: string, timeout: int}
     */
    public static function resolveConfig(?TenantSettings $settings): array
    {
        $baseUrl = $settings?->atpOciWalletBaseUrl() ?: (string) config('atp_oci.base_url', '');
        $apiKey = $settings?->atpOciWalletApiKey() ?: (config('atp_oci.api_key') ? (string) config('atp_oci.api_key') : null);
        $verifyPath = $settings?->atpOciVerifyPath() ?: (string) config('atp_oci.verify_path', '/api/v1/verify-vp');
        $presentPath = $settings?->atpOciPresentPath() ?: (string) config('atp_oci.present_path', '/api/v1/generate-vp');
        $timeout = (int) config('atp_oci.timeout', 15);

        return [
            'base_url' => rtrim(trim($baseUrl), '/'),
            'api_key' => filled($apiKey) ? (string) $apiKey : null,
            'verify_path' => '/'.ltrim($verifyPath, '/'),
            'present_path' => '/'.ltrim($presentPath, '/'),
            'timeout' => max(1, $timeout),
        ];
    }

    public function isConfigured(): bool
    {
        return $this->config['base_url'] !== '';
    }

    public function verify(string $vp): OciVerifyResult
    {
        $vp = trim($vp);
        if ($vp === '') {
            return OciVerifyResult::missing();
        }

        if (! $this->isConfigured()) {
            return OciVerifyResult::error('OCI wallet is not configured');
        }

        try {
            $url = $this->config['base_url'].$this->config['verify_path'];
            $this->assertSafeUrl($url);

            $pending = $this->http($url);
            $response = $pending->post($url, ['vp' => $vp])->throw();
            $body = $response->json();

            if (! is_array($body)) {
                return OciVerifyResult::error('OCI wallet verify returned a non-JSON body');
            }

            return OciVerifyResult::fromWalletResponse($body);
        } catch (Throwable $exception) {
            Log::warning('OCI wallet verify failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return OciVerifyResult::error('OCI wallet verify failed: '.$exception->getMessage());
        }
    }

    public function present(?string $subjectGln = null): string
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('OCI wallet is not configured for present().');
        }

        $url = $this->config['base_url'].$this->config['present_path'];
        $this->assertSafeUrl($url);

        $payload = [];
        if (filled($subjectGln)) {
            $payload['subject_gln'] = preg_replace('/\D+/', '', (string) $subjectGln) ?: (string) $subjectGln;
        }

        $response = $this->http($url)->post($url, $payload)->throw();
        $body = $response->json();

        if (! is_array($body)) {
            throw new \RuntimeException('OCI wallet present returned a non-JSON body.');
        }

        $vp = $body['vp'] ?? $body['presentation'] ?? null;
        if (! is_string($vp) || trim($vp) === '') {
            throw new \RuntimeException('OCI wallet present response missing vp.');
        }

        return trim($vp);
    }

    private function assertSafeUrl(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === 'https') {
            EpcisSubscriptionUrl::assertSafeTargetUrl($url);
            if (! app()->runningUnitTests()) {
                EpcisSubscriptionUrl::assertSafeAtConnect($url);
            }

            return;
        }

        if ($scheme === 'http') {
            TenantSettings::assertAndResolveWmsStyleHost($url);

            return;
        }

        throw new \InvalidArgumentException('OCI wallet URL must be http(s).');
    }

    private function http(string $url)
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $timeout = $this->config['timeout'];

        $pending = $scheme === 'https'
            ? EpcisSubscriptionUrl::httpClient($url, $timeout)
            : TenantSettings::wmsStylePinnedHttpClient($url, $timeout);

        if (filled($this->config['api_key'])) {
            $pending = $pending->withToken((string) $this->config['api_key']);
        }

        return $pending->acceptJson()->asJson();
    }
}
