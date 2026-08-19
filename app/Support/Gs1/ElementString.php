<?php

namespace App\Support\Gs1;

/**
 * GS1 Application Identifier (AI) element-string parsing for scan ingest.
 *
 * Supports parenthesized form such as (01)…(21)…(17)…(10)…, FNC1 (GS, \x1D)
 * delimited concatenations in any AI order, and unparenthesized chains where
 * fixed-length AIs can be consumed positionally.
 */
final class ElementString
{
    /**
     * FNC1 / GS — the standard terminator for a variable-length AI value.
     */
    public const FNC1 = "\x1D";

    /**
     * Fixed-length AI values (AI => value length), so the parser can continue
     * reading the next AI without a separator.
     *
     * @var array<string, int>
     */
    private const FIXED_LENGTH_AIS = [
        '00' => 18,
        '01' => 14,
        '02' => 14,
        '11' => 6,
        '12' => 6,
        '13' => 6,
        '15' => 6,
        '16' => 6,
        '17' => 6,
        '20' => 2,
    ];

    /**
     * Variable-length AIs. Their value runs to the next FNC1 or the end of input.
     *
     * @var list<string>
     */
    private const VARIABLE_LENGTH_AIS = ['10', '21', '22', '240', '241', '250', '254', '30'];

    /**
     * Strip scanner/control noise; preserve alphanumeric case for serials.
     *
     * Removes:
     * - ASCII controls (incl. GS/FNC1 \x1D, RS, CR/LF, STX/ETX)
     * - Zero-width / BOM / non-breaking spaces
     * - AIM symbology identifiers (]C1, ]e0, ]d2, …)
     * - Remaining whitespace
     *
     * This is the display/storage form. Parsing uses {@see normalizeSegments()},
     * which keeps FNC1 so variable-length AI values stay delimited.
     */
    public static function normalize(string $input): string
    {
        $normalized = str_replace("\x00", '', $input);

        // ASCII C0 controls + DEL (covers FNC1/GS as \x1D, CR/LF, STX/ETX, etc.).
        $normalized = preg_replace('/[\x00-\x1F\x7F]/u', '', $normalized) ?? '';

        // Zero-width, BOM, NBSP, and other format chars scanners sometimes inject.
        $normalized = preg_replace('/[\x{00A0}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $normalized) ?? '';

        // AIM symbology identifier prefix, e.g. ]C1 ]e0 ]d2 ]Q3.
        $normalized = preg_replace('/^\][A-Za-z0-9]{2}/', '', $normalized) ?? '';

        $normalized = preg_replace('/\s+/u', '', $normalized) ?? '';

        return $normalized;
    }

    /**
     * Normalize each FNC1-delimited segment while keeping the separators intact.
     *
     * Input that already went through {@see normalize()} yields a single segment,
     * so callers holding a normalized scan keep working unchanged.
     */
    public static function normalizeSegments(string $input): string
    {
        $segments = [];

        foreach (explode(self::FNC1, $input) as $segment) {
            $clean = self::normalize($segment);
            if ($clean !== '') {
                $segments[] = $clean;
            }
        }

        return implode(self::FNC1, $segments);
    }

    /**
     * Parse a GS1 element string into AI => value pairs.
     *
     * @return array<string, string>
     */
    public static function parse(string $input): array
    {
        $normalized = self::normalizeSegments($input);

        if ($normalized === '') {
            return [];
        }

        if (str_contains($normalized, '(')) {
            return self::parseParenthesized(str_replace(self::FNC1, '', $normalized));
        }

        return self::parseUnparenthesized($normalized);
    }

    /**
     * Extract SGTIN identity (GTIN + serial) and optional lot/expiry from a scan string.
     *
     * @return array{
     *     gtin14: string,
     *     serial: string,
     *     ai_01_21: string,
     *     lot_number?: string,
     *     expiry_yymmdd?: string
     * }|null
     */
    public static function sgtinIdentity(string $input): ?array
    {
        $ais = self::parse($input);

        $gtin14 = $ais['01'] ?? null;
        $serial = $ais['21'] ?? null;

        if ($gtin14 === null || $serial === null) {
            return null;
        }

        if (strlen($gtin14) !== 14 || ! ctype_digit($gtin14)) {
            return null;
        }

        $body = substr($gtin14, 0, 13);
        if (substr($gtin14, 13, 1) !== Gtin::checkDigit($body)) {
            return null;
        }

        $result = [
            'gtin14' => $gtin14,
            'serial' => $serial,
            'ai_01_21' => '01'.$gtin14.'21'.$serial,
        ];

        if (isset($ais['10']) && $ais['10'] !== '') {
            $result['lot_number'] = $ais['10'];
        }

        if (isset($ais['17']) && preg_match('/^\d{6}$/', $ais['17'])) {
            $result['expiry_yymmdd'] = $ais['17'];
        }

        return $result;
    }

    /**
     * Concatenate an SGTIN element string for display: 01 + GTIN + 21 + serial,
     * then 17 + YYMMDD and 10 + lot when expiry is present.
     *
     * AI 10 is omitted without AI 17 so the string stays parseable without FNC1.
     */
    public static function encodeSgtin(
        string $gtin14,
        string $serial,
        ?string $lot = null,
        ?string $expiryYymmdd = null,
    ): string {
        $encoded = '01'.$gtin14.'21'.$serial;

        $expiry = filled($expiryYymmdd) && preg_match('/^\d{6}$/', (string) $expiryYymmdd) === 1
            ? (string) $expiryYymmdd
            : null;

        if ($expiry === null) {
            return $encoded;
        }

        $encoded .= '17'.$expiry;

        if (filled($lot)) {
            $encoded .= '10'.$lot;
        }

        return $encoded;
    }

    /**
     * Extract SSCC identity from 18-digit, 20-digit (00…), or (00)… forms.
     *
     * @return array{sscc18: string, ai_00: string}|null
     */
    public static function ssccIdentity(string $input): ?array
    {
        $normalized = self::normalizeSegments($input);

        if ($normalized === '') {
            return null;
        }

        $ais = self::parse($normalized);

        if (isset($ais['00'])) {
            return Sscc::fromSscc18($ais['00']);
        }

        $digits = preg_replace('/\D+/', '', $normalized) ?? '';

        if (strlen($digits) === 18) {
            return Sscc::fromSscc18($digits);
        }

        if (strlen($digits) === 20 && str_starts_with($digits, '00')) {
            return Sscc::fromSscc18(substr($digits, 2));
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function parseParenthesized(string $input): array
    {
        $ais = [];

        if (! preg_match_all('/\((\d{2,4})\)([^\(]*)/', $input, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $ai = $match[1];
            $value = $match[2];

            if ($value === '') {
                continue;
            }

            $ais[$ai] = $value;
        }

        return $ais;
    }

    /**
     * Parse concatenated AI chains, honouring FNC1 as the variable-length terminator.
     *
     * @return array<string, string>
     */
    private static function parseUnparenthesized(string $input): array
    {
        if (preg_match('/^00(\d{18})$/', $input, $matches) === 1) {
            return ['00' => $matches[1]];
        }

        if (preg_match('/^\d{18}$/', $input) === 1) {
            return ['00' => $input];
        }

        $ais = [];

        foreach (explode(self::FNC1, $input) as $segment) {
            foreach (self::parseSegment($segment) as $ai => $value) {
                // First occurrence wins; a repeated AI in a later segment is not
                // allowed to overwrite the identity already established.
                $ais[$ai] ??= $value;
            }
        }

        return $ais;
    }

    /**
     * Parse one FNC1-free segment: fixed-length AIs are consumed by length, and the
     * first variable-length AI takes the rest of the segment.
     *
     * @return array<string, string>
     */
    private static function parseSegment(string $segment): array
    {
        $ais = [];
        $cursor = $segment;

        while ($cursor !== '') {
            $ai = substr($cursor, 0, 2);

            if (isset(self::FIXED_LENGTH_AIS[$ai])) {
                $length = self::FIXED_LENGTH_AIS[$ai];
                $value = substr($cursor, 2, $length);

                if (strlen($value) !== $length || ! ctype_digit($value)) {
                    break;
                }

                $ais[$ai] ??= $value;
                $cursor = substr($cursor, 2 + $length);

                continue;
            }

            if (! in_array($ai, self::VARIABLE_LENGTH_AIS, true)) {
                $ai = substr($cursor, 0, 3);

                if (! in_array($ai, self::VARIABLE_LENGTH_AIS, true)) {
                    break;
                }
            }

            $value = substr($cursor, strlen($ai));

            if ($value === '') {
                break;
            }

            if ($ai === '21') {
                [$serial, $trailing] = self::splitSerialOnTrailingExpiry($value);
                $ais['21'] ??= $serial;
                $cursor = $trailing;

                continue;
            }

            $ais[$ai] ??= $value;
            $cursor = '';
        }

        return $ais;
    }

    /**
     * Split an unterminated AI 21 value where a trailing AI 17 (+ AI 10) follows.
     *
     * AI 21 is variable-length, so without FNC1 the only safe split is a strictly
     * numeric serial followed by a plausible YYMMDD and either nothing or an AI 10
     * lot. Alphanumeric serials — including ones containing "17" — are never cut,
     * and neither are numeric serials whose "17" is not followed by a real date.
     *
     * @return array{0: string, 1: string} Serial, plus the unconsumed remainder.
     */
    private static function splitSerialOnTrailingExpiry(string $value): array
    {
        if (preg_match('/^(\d+)17(\d{6})(.*)$/', $value, $matches) !== 1) {
            return [$value, ''];
        }

        [, $serial, $expiry, $trailing] = $matches;

        if (! self::isPlausibleYymmdd($expiry)) {
            return [$value, ''];
        }

        $trailingIsLot = $trailing === ''
            || (str_starts_with($trailing, '10') && strlen($trailing) > 2);

        if (! $trailingIsLot) {
            return [$value, ''];
        }

        return [$serial, '17'.$expiry.$trailing];
    }

    /**
     * GS1 AI 17 uses YYMMDD, where DD may be 00 for "end of month".
     */
    private static function isPlausibleYymmdd(string $value): bool
    {
        if (preg_match('/^\d{6}$/', $value) !== 1) {
            return false;
        }

        $month = (int) substr($value, 2, 2);
        $day = (int) substr($value, 4, 2);

        return $month >= 1 && $month <= 12 && $day >= 0 && $day <= 31;
    }
}
