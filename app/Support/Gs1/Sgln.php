<?php

namespace App\Support\Gs1;

/**
 * GS1 SGLN helpers for EPCIS location identity.
 *
 * Encodes urn:epc:id:sgln:{companyPrefix}.{locationReference}.{extension}
 * into a 13-digit GLN when companyPrefix + locationReference total 12 digits.
 */
final class Sgln
{
    /**
     * Parse an SGLN Pure Identity URN into GLN fields.
     *
     * @return array{
     *     gln_uri: string,
     *     gln: string,
     *     company_prefix: string,
     *     location_reference: string,
     *     extension: string
     * }|null
     */
    public static function fromUrn(string $uri): ?array
    {
        $uri = trim($uri);

        if (! preg_match('/^urn:epc:id:sgln:(\d+)\.(\d+)\.(\d+)$/', $uri, $matches)) {
            return null;
        }

        $companyPrefix = $matches[1];
        $locationReference = $matches[2];
        $extension = $matches[3];
        $body12 = $companyPrefix.$locationReference;

        if (strlen($body12) !== 12 || ! ctype_digit($body12)) {
            return null;
        }

        return [
            'gln_uri' => $uri,
            'gln' => $body12.Gtin::checkDigit($body12),
            'company_prefix' => $companyPrefix,
            'location_reference' => $locationReference,
            'extension' => $extension,
        ];
    }

    /**
     * Build an SGLN URN from a 13-digit GLN when the GS1 company-prefix length is known.
     * Returns null rather than guessing a 6/6 split (invalid for many prefixes).
     */
    public static function toUrn(?string $gln, int $companyPrefixLength, string $extension = '0'): ?string
    {
        $normalized = self::normalizeGln($gln);

        if ($normalized === null) {
            return null;
        }

        if ($companyPrefixLength < 6 || $companyPrefixLength > 11) {
            return null;
        }

        $body12 = substr($normalized, 0, 12);
        $companyPrefix = substr($body12, 0, $companyPrefixLength);
        $locationReference = substr($body12, $companyPrefixLength);

        if ($companyPrefix === '' || $locationReference === '' || ! ctype_digit($companyPrefix.$locationReference)) {
            return null;
        }

        return 'urn:epc:id:sgln:'.$companyPrefix.'.'.$locationReference.'.'.$extension;
    }

    /**
     * Resolve an SGLN URN for a GLN using an optional hint URN and/or candidate URNs
     * (e.g. inbound destinationList). Never invents a fake company-prefix length.
     *
     * @param  list<string>  $candidateUrns
     */
    public static function resolveUrn(?string $gln, ?string $hintUrn = null, array $candidateUrns = []): ?string
    {
        $normalized = self::normalizeGln($gln);

        if ($normalized === null) {
            return null;
        }

        foreach (array_filter([$hintUrn, ...$candidateUrns]) as $uri) {
            if (! is_string($uri) || $uri === '') {
                continue;
            }

            $parsed = self::fromUrn($uri);
            if ($parsed !== null && $parsed['gln'] === $normalized) {
                return $parsed['gln_uri'];
            }
        }

        return null;
    }

    public static function normalizeGln(?string $gln): ?string
    {
        if ($gln === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $gln) ?? '';

        if (strlen($digits) !== 13 || ! ctype_digit($digits)) {
            return null;
        }

        return $digits;
    }
}
