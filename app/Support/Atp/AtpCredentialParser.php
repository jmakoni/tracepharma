<?php

declare(strict_types=1);

namespace App\Support\Atp;

use Carbon\Carbon;

/**
 * Parses OCI-style ATP verifiable credential headers (JWT compact form) for
 * local claim display. Payload claims are read WITHOUT signature verification —
 * cryptographic verify belongs to OciWalletClient when tenant OCI mode is
 * warn/require. This parser remains payload-only after wallet verify.
 */
class AtpCredentialParser
{
    /**
     * @return array{issuer: ?string, subject_gln: ?string, expires_at: ?Carbon}|null
     */
    public static function parse(string $header): ?array
    {
        $header = trim($header);

        if ($header === '') {
            return null;
        }

        // Strip a leading "Bearer " scheme if present.
        if (str_starts_with(strtolower($header), 'bearer ')) {
            $header = trim(substr($header, 7));
        }

        $parts = explode('.', $header);

        if (count($parts) < 2) {
            return null;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);

        if ($payload === false) {
            return null;
        }

        $claims = json_decode($payload, true);

        if (! is_array($claims)) {
            return null;
        }

        $issuer = isset($claims['iss']) && is_string($claims['iss']) ? $claims['iss'] : null;

        $subjectGln = null;

        foreach (['sub', 'subject_gln', 'gln'] as $key) {
            $candidate = $claims[$key] ?? null;

            if (! is_string($candidate)) {
                continue;
            }

            // Accept bare GLNs and SGLN URNs (urn:epc:id:sgln:0300001.00001.0).
            $digits = preg_replace('/\D+/', '', $candidate) ?? '';

            if (strlen($digits) === 13) {
                $subjectGln = $digits;
                break;
            }

            if (preg_match('/\d{13}/', $candidate, $m) === 1) {
                $subjectGln = $m[0];
                break;
            }
        }

        $expiresAt = null;

        if (isset($claims['exp']) && is_numeric($claims['exp'])) {
            $expiresAt = Carbon::createFromTimestampUTC((int) $claims['exp']);
        }

        return [
            'issuer' => $issuer,
            'subject_gln' => $subjectGln,
            'expires_at' => $expiresAt,
        ];
    }
}
