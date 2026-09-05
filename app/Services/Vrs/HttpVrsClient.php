<?php

namespace App\Services\Vrs;

use App\Exceptions\VrsConfigurationException;
use App\Models\Tenant;
use App\Services\Vrs\Contracts\VrsClient;
use App\Support\Epcis\EpcisSubscriptionUrl;
use App\Support\TenantSettings;
use App\Support\Vrs\VrsLogCorrelation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class HttpVrsClient implements VrsClient
{
    /**
     * `verifications.message` is a 512 char column; a Guzzle exception message carries the
     * request line and a body snippet, so it is trimmed before it reaches the row.
     */
    private const MAX_MESSAGE_LENGTH = 400;

    /**
     * Hosts that ship as config examples rather than a real Verification Router Service.
     * Matched on the host itself and on any `*.example.tld` subdomain.
     */
    private const PLACEHOLDER_HOSTS = [
        'example.com',
        'example.org',
        'example.net',
        'example.test',
    ];

    private const PLACEHOLDER_MARKERS = [
        'changeme',
        'placeholder',
        'your-vrs',
        'vrs-host',
    ];

    public function __construct()
    {
        self::assertConfigured();
    }

    /**
     * Fail loudly when the http driver has no usable endpoint. Also callable from a
     * deploy/boot check so a misconfigured environment surfaces before the first scan.
     *
     * @throws VrsConfigurationException
     */
    public static function assertConfigured(): void
    {
        $baseUrl = trim((string) config('vrs.http.base_url'));

        if ($baseUrl === '') {
            throw new VrsConfigurationException(
                'VRS_BASE_URL must be set when VRS_DRIVER=http.',
            );
        }

        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            throw new VrsConfigurationException(
                'VRS_BASE_URL must be an absolute http(s) URL, got "'.$baseUrl.'".',
            );
        }

        if (self::isPlaceholderHost($host)) {
            throw new VrsConfigurationException(
                'VRS_BASE_URL still points at the example host "'.$host.'". '
                .'Set the Verification Router Service endpoint for this environment.',
            );
        }

        self::assertEgressHostAllowed($baseUrl, $scheme, $host);
    }

    /**
     * Deny loopback / link-local / metadata / private addresses for VRS egress.
     * HTTPS uses the full subscription SSRF guard; HTTP uses WMS-style deny (RFC1918 allowed).
     *
     * @throws VrsConfigurationException
     */
    private static function assertEgressHostAllowed(string $baseUrl, string $scheme, string $host): void
    {
        if ($scheme === 'https') {
            try {
                EpcisSubscriptionUrl::assertSafeTargetUrl($baseUrl);
                // Resolve when possible; unresolvable hosts fail closed outside unit tests.
                if (! app()->runningUnitTests()) {
                    EpcisSubscriptionUrl::assertSafeAtConnect($baseUrl);
                } else {
                    try {
                        EpcisSubscriptionUrl::assertSafeAtConnect($baseUrl);
                    } catch (\InvalidArgumentException $exception) {
                        if (! str_contains($exception->getMessage(), 'could not be resolved')) {
                            throw $exception;
                        }
                    }
                }
            } catch (\InvalidArgumentException $exception) {
                throw new VrsConfigurationException($exception->getMessage(), 0, $exception);
            }

            return;
        }

        try {
            TenantSettings::assertAndResolveWmsStyleHost($baseUrl);
        } catch (\InvalidArgumentException $exception) {
            throw new VrsConfigurationException(
                str_contains(strtolower($exception->getMessage()), 'private or metadata')
                    ? 'VRS_BASE_URL must not target a private or metadata host.'
                    : $exception->getMessage(),
                0,
                $exception,
            );
        }
    }

    public function verify(
        string $gtin14,
        string $serial,
        ?string $lot = null,
        ?string $expiryYymmdd = null,
    ): array {
        // A plain array_filter() drops falsy values, which would silently strip a
        // legitimate serial or lot of "0" — keep every value except null/empty string.
        $request = array_filter([
            'gtin' => $gtin14,
            'serial' => $serial,
            'lot' => $lot,
            'expiry' => $expiryYymmdd,
            'requestor_gln' => $this->requestorGln(),
        ], fn ($value): bool => $value !== null && $value !== '');

        $baseUrl = (string) config('vrs.http.base_url');
        $path = (string) config('vrs.http.verify_path', '/api/v1/verify');
        $timeout = (int) config('vrs.http.timeout', 30);
        $apiKey = config('vrs.http.api_key');

        $base = [
            'gtin14' => $gtin14,
            'serial' => $serial,
            'lot' => $lot,
            'expiry_yymmdd' => $expiryYymmdd,
        ];

        try {
            $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
            $pending = $scheme === 'https'
                ? EpcisSubscriptionUrl::httpClient($baseUrl, $timeout)
                : TenantSettings::wmsStylePinnedHttpClient($baseUrl, $timeout);

            if (filled($apiKey)) {
                $pending = $pending->withToken((string) $apiKey);
            }

            $response = $pending
                ->post(rtrim($baseUrl, '/').$path, $request)
                ->throw();

            $httpStatus = $response->status();
            $rawBody = Str::limit((string) $response->body(), 2000);
            $body = $response->json();
            $httpEvidence = [
                'http_status' => $httpStatus,
                'http_body' => $rawBody,
            ];

            if (! self::isVerdictShaped($body)) {
                // A 2xx with no body, a non-JSON body, or a JSON value that carries none of
                // the fields a verdict would use is not an answer about the product — treat
                // it the same as an unreachable endpoint rather than manufacturing a verdict.
                return $this->transportFailure(
                    $base,
                    'unavailable',
                    'VRS response malformed',
                    new \RuntimeException('VRS returned a 2xx response without a recognizable verification payload.'),
                    $httpEvidence,
                );
            }

            $verified = (bool) ($body['verified'] ?? (($body['status'] ?? null) === 'verified'));

            if ($verified) {
                return [
                    ...$base,
                    ...$httpEvidence,
                    'status' => 'verified',
                    'message' => (string) ($body['message'] ?? 'Product verified.'),
                ];
            }

            $reason = (string) ($body['reason_code'] ?? $body['status'] ?? 'not_verified');

            // "not_in_network" / "unknown" mean the VRS has no coverage or no record for this
            // product — a routing/coverage gap, not an affirmative bad verdict. Treating it as
            // suspect would quarantine good stock every time a responder simply doesn't know.
            // RunProductVerification::shouldOpenException already skips quarantine/exception
            // for transport-failure-shaped statuses, so this is reported the same way.
            if (in_array($reason, ['not_in_network', 'unknown'], true)) {
                return [
                    ...$base,
                    ...$httpEvidence,
                    'status' => 'unavailable',
                    'message' => (string) ($body['message'] ?? 'VRS has no coverage or record for this product — retry or verify manually.'),
                ];
            }

            return [
                ...$base,
                ...$httpEvidence,
                'status' => $reason === 'suspect' ? 'suspect' : 'failed',
                'message' => (string) ($body['message'] ?? 'Verification did not confirm this product.'),
            ];
        } catch (ConnectionException $exception) {
            // DNS, TLS and timeout failures: the VRS never answered, so nothing is known
            // about the product. Reported as unavailable for retry, never as suspect.
            return $this->transportFailure($base, 'unavailable', 'VRS unreachable', $exception);
        } catch (RequestException $exception) {
            // The endpoint answered with 4xx/5xx — a system fault on one side of the
            // exchange, still not a verdict about the product.
            $evidence = [];
            $response = $exception->response;
            if ($response !== null) {
                $evidence = [
                    'http_status' => $response->status(),
                    'http_body' => Str::limit((string) $response->body(), 2000),
                ];
            }

            return $this->transportFailure($base, 'error', 'VRS request failed', $exception, $evidence);
        } catch (Throwable $exception) {
            // Anything else (malformed URL, redirect loop, JSON decode) must still return a
            // result array so the attempt is persisted as a Verification for the audit trail.
            return $this->transportFailure($base, 'error', 'VRS request failed', $exception);
        }
    }

    /**
     * @param  array{gtin14: string, serial: string, lot: ?string, expiry_yymmdd: ?string}  $base
     * @param  array{http_status?: int, http_body?: string}  $httpEvidence
     * @return array{gtin14: string, serial: string, lot: ?string, expiry_yymmdd: ?string, status: string, message: string, http_status?: int, http_body?: string}
     */
    private function transportFailure(
        array $base,
        string $status,
        string $label,
        Throwable $exception,
        array $httpEvidence = [],
    ): array {
        Log::warning('VRS verification could not complete.', [
            'status' => $status,
            'identity_hash' => VrsLogCorrelation::hash($base['gtin14'], $base['serial']),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        return [
            ...$base,
            ...$httpEvidence,
            'status' => $status,
            'message' => Str::limit(
                $label.': '.$exception->getMessage(),
                self::MAX_MESSAGE_LENGTH,
            ),
        ];
    }

    /**
     * True when the decoded JSON body is an associative array carrying at least one of
     * the fields a real verdict would use. An empty body, a scalar/list JSON value, or an
     * object with none of these keys means the responder did not actually answer.
     */
    private static function isVerdictShaped(mixed $body): bool
    {
        if (! is_array($body) || $body === []) {
            return false;
        }

        return array_key_exists('verified', $body)
            || array_key_exists('status', $body)
            || array_key_exists('reason_code', $body);
    }

    private static function isPlaceholderHost(string $host): bool
    {
        foreach (self::PLACEHOLDER_HOSTS as $placeholder) {
            if ($host === $placeholder || str_ends_with($host, '.'.$placeholder)) {
                return true;
            }
        }

        foreach (self::PLACEHOLDER_MARKERS as $marker) {
            if (str_contains($host, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * GLN identifying this tenant as the requestor, per the VRS lookup directory.
     *
     * Falls back to the configured GLN outside tenant context (queued jobs, CLI).
     */
    private function requestorGln(): ?string
    {
        $tenant = function_exists('tenant') && tenancy()->initialized ? tenant() : null;

        $gln = $tenant instanceof Tenant
            ? TenantSettings::forTenant($tenant)->gln()
            : null;

        $gln ??= config('vrs.http.requestor_gln');

        return filled($gln) ? (string) $gln : null;
    }
}
