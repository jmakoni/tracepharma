<?php

namespace App\Support\Gs1;

use App\Rules\ValidGln;

/**
 * The SGLN URN to author for a GLN — never an invented one.
 *
 * A GLN carries no marker for where its GS1 Company Prefix ends, so the split into
 * {companyPrefix}.{locationReference} cannot be recovered from the 13 digits. Only
 * two sources know it: an SGLN already on record for that location (the partner's
 * own, as they publish it), and a GS1 Company Prefix we already know — the
 * organization prefix, or one recorded on another of our own facilities.
 *
 * Guessing the split still round-trips to the same GLN, which is why it looks
 * harmless — but the URN is the location's identity on a DSCSA transaction
 * information record, and a partner who resolves ours against theirs sees a
 * location that is not the one they registered. When neither source answers, this
 * returns null and the caller refuses to author rather than shipping a fiction.
 */
final class SglnResolution
{
    /**
     * @param  list<mixed>  $candidates  SGLN URNs on record for this location
     * @param  ?string  $companyPrefix  our GS1 Company Prefix, used only for GLNs issued under it
     * @param  list<string|null>  $additionalPrefixes  other prefixes we already know (sibling org sites)
     */
    public static function resolve(
        ?string $gln,
        array $candidates = [],
        ?string $companyPrefix = null,
        array $additionalPrefixes = [],
    ): ?string {
        $normalized = Sgln::normalizeGln($gln);

        if ($normalized === null) {
            return null;
        }

        $recorded = Sgln::resolveUrn($normalized, null, self::urnCandidates($candidates));

        if ($recorded !== null) {
            return $recorded;
        }

        foreach (self::prefixList($companyPrefix, $additionalPrefixes) as $prefix) {
            $encoded = self::fromCompanyPrefix($normalized, $prefix);
            if ($encoded !== null) {
                return $encoded;
            }
        }

        return null;
    }

    /**
     * @param  list<string|null>  $additionalPrefixes
     * @return list<string>
     */
    private static function prefixList(?string $companyPrefix, array $additionalPrefixes): array
    {
        $prefixes = [];

        foreach ([$companyPrefix, ...$additionalPrefixes] as $prefix) {
            $digits = preg_replace('/\D+/', '', (string) $prefix) ?? '';
            if ($digits !== '') {
                $prefixes[$digits] = $digits;
            }
        }

        return array_values($prefixes);
    }

    /**
     * Encode a GLN that sits under the given GS1 Company Prefix, where the split is known.
     *
     * @param  string  $extension  the sub-location this URN already named, if any
     */
    public static function fromCompanyPrefix(?string $gln, ?string $companyPrefix, string $extension = '0'): ?string
    {
        $normalized = ValidGln::normalize($gln);
        $prefix = preg_replace('/\D+/', '', (string) $companyPrefix) ?? '';

        if ($normalized === null || $prefix === '' || ! ctype_digit($extension)) {
            return null;
        }

        if (! str_starts_with(substr($normalized, 0, 12), $prefix)) {
            return null;
        }

        return Sgln::toUrn($normalized, strlen($prefix), $extension);
    }

    /**
     * Encode a GLN using only the length of a known GS1 Company Prefix (6–11).
     *
     * Used as a last resort for organization facilities whose GLN is not issued
     * under the organization prefix or a sibling facility prefix. Do not use this
     * for partner locations — their split has to come from the SGLN they publish.
     *
     * @param  string  $extension  the sub-location this URN already named, if any
     */
    public static function fromPrefixLength(?string $gln, ?string $companyPrefix, string $extension = '0'): ?string
    {
        // Require a GS1 Mod-10 check digit so the URN round-trips to the same GLN.
        // A 13-digit string with a wrong check digit still yields a URN from the
        // first 12 digits — but that URN encodes a *different* GLN, and receiving
        // / shipping authoring then refuses to build readPoint/bizLocation.
        $normalized = ValidGln::normalize($gln);
        $prefix = preg_replace('/\D+/', '', (string) $companyPrefix) ?? '';
        $length = strlen($prefix);

        if ($normalized === null || $length < 6 || $length > 11 || ! ctype_digit($extension)) {
            return null;
        }

        return Sgln::toUrn($normalized, $length, $extension);
    }

    /**
     * Extension from a URN that still names this GLN; otherwise the GLN itself (0).
     */
    public static function extensionOf(?string $current, ?string $gln): string
    {
        $parsed = $current !== null ? Sgln::fromUrn($current) : null;

        return $parsed !== null && $parsed['gln'] === Sgln::normalizeGln($gln)
            ? $parsed['extension']
            : '0';
    }

    /**
     * Parseable SGLN URNs out of mixed values — a stored column, a party hint, an
     * inbound destinationList entry. Legacy two-segment values are dropped here.
     *
     * @param  list<mixed>  $values
     * @return list<string>
     */
    public static function urnCandidates(array $values): array
    {
        $candidates = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '' && Sgln::fromUrn($value) !== null) {
                $candidates[] = $value;
            }
        }

        return $candidates;
    }
}
