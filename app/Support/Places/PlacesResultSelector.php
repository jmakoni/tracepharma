<?php

namespace App\Support\Places;

use App\Support\PartnerSlug;

/**
 * Filters and ranks raw Places API results for a trading partner, picking
 * the best headquarters candidate and the remaining plausible sites.
 */
final class PlacesResultSelector
{
    /**
     * Canonical (lowercase, space-separated) category keywords that make a
     * result look like a corporate HQ / manufacturer rather than a storefront.
     *
     * @var list<string>
     */
    private const PREFERRED_CATEGORIES = [
        'corporate office',
        'pharmaceutical company',
        'manufacturer',
        'company',
        'headquarters',
    ];

    /**
     * Category keywords that suggest a retail/logistics location rather
     * than a corporate site, and should be scored down.
     *
     * @var list<string>
     */
    private const DEMOTED_KEYWORDS = [
        'pharmacy',
        'transportation',
        'store',
    ];

    /**
     * @var list<string>
     */
    private const CLOSED_STATUSES = [
        'closed_permanently',
        'closed_temporarily',
    ];

    private const VERIFIED_POINTS = 50;

    private const PREFERRED_CATEGORY_POINTS = 30;

    private const PREFERRED_CATEGORY_CAP = 3;

    private const DEMOTION_PENALTY = 40;

    private const WEBSITE_POINTS = 10;

    private const REVIEW_COUNT_DIVISOR = 10;

    private const REVIEW_COUNT_CAP = 5.0;

    /**
     * Industry / legal / geo tokens removed before core-brand and fuzzy matching.
     * Applied to both partner and API name slugs.
     *
     * @var list<string>
     */
    private const CORE_STOP_TOKENS = [
        'pharma',
        'pharmaceutical',
        'pharmaceuticals',
        'biotech',
        'biotechnology',
        'biopharma',
        'lab',
        'labs',
        'laboratory',
        'laboratories',
        'medical',
        'medicine',
        'supply',
        'supplies',
        'health',
        'healthcare',
        'pharmacy',
        'distribution',
        'distributor',
        'wholesale',
        'manufacturing',
        'manufacturer',
        'mfg',
        'group',
        'holdings',
        'international',
        'global',
        'industries',
        'industry',
        'solutions',
        'services',
        'systems',
        'products',
        'enterprises',
        'enterprise',
        'inc',
        'corp',
        'co',
        'ltd',
        'llc',
        'limited',
        'company',
        'incorporated',
        'corporation',
        'usa',
        'us',
    ];

    private const MIN_CORE_SUBSTRING_LENGTH = 5;

    private const MIN_FUZZY_COMPACT_LENGTH = 5;

    private const FUZZY_SIMILAR_TEXT_PERCENT = 85.0;

    private const FUZZY_LEVENSHTEIN_RATIO = 0.15;

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array{hq: ?array<string, mixed>, sites: list<array<string, mixed>>, rejected: int}
     */
    public function select(string $partnerName, array $results): array
    {
        $partnerSlug = PartnerSlug::from($partnerName);

        $filtered = [];
        $rejected = 0;

        foreach ($results as $result) {
            if (! is_array($result)
                || $this->isClosed($result)
                || ! $this->hasAddress($result)
                || ! $this->hasCoordinates($result)
                || ! $this->matchesPartnerName($partnerSlug, (string) ($result['name'] ?? ''))
            ) {
                $rejected++;

                continue;
            }

            $filtered[] = $result;
        }

        if ($filtered === []) {
            return ['hq' => null, 'sites' => [], 'rejected' => $rejected];
        }

        $scored = array_map(
            fn (array $result): array => ['result' => $result, 'score' => $this->score($result)],
            $filtered
        );

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $hq = $scored[0]['result'];
        $hqPlaceId = $hq['place_id'] ?? null;

        $sites = [];
        foreach (array_slice($scored, 1) as $entry) {
            if (($entry['result']['place_id'] ?? null) !== $hqPlaceId) {
                $sites[] = $entry['result'];
            }
        }

        return ['hq' => $hq, 'sites' => $sites, 'rejected' => $rejected];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function isClosed(array $result): bool
    {
        $status = strtolower(trim((string) ($result['business_status'] ?? '')));

        return in_array($status, self::CLOSED_STATUSES, true);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function hasAddress(array $result): bool
    {
        return filled($result['street_address'] ?? null) || filled($result['address'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function hasCoordinates(array $result): bool
    {
        return is_numeric($result['latitude'] ?? null) && is_numeric($result['longitude'] ?? null);
    }

    private function matchesPartnerName(string $partnerSlug, string $apiName): bool
    {
        if (trim($apiName) === '') {
            return false;
        }

        $apiSlug = PartnerSlug::from($apiName);

        if ($apiSlug === $partnerSlug) {
            return true;
        }

        $partnerToken = $this->firstSignificantToken($partnerSlug);
        $apiToken = $this->firstSignificantToken($apiSlug);

        if ($partnerToken !== null && $partnerToken === $apiToken) {
            return true;
        }

        $shorter = strlen($apiSlug) <= strlen($partnerSlug) ? $apiSlug : $partnerSlug;
        $longer = $shorter === $apiSlug ? $partnerSlug : $apiSlug;

        if (strlen($shorter) >= 3 && str_contains($longer, $shorter)) {
            return true;
        }

        $partnerCore = $this->coreSlug($partnerSlug);
        $apiCore = $this->coreSlug($apiSlug);

        if ($partnerCore === '' || $apiCore === '') {
            return false;
        }

        if ($partnerCore === $apiCore) {
            return true;
        }

        $coreShorter = strlen($apiCore) <= strlen($partnerCore) ? $apiCore : $partnerCore;
        $coreLonger = $coreShorter === $apiCore ? $partnerCore : $apiCore;

        if (strlen($coreShorter) >= self::MIN_CORE_SUBSTRING_LENGTH && str_contains($coreLonger, $coreShorter)) {
            return true;
        }

        return $this->fuzzyCoreMatch($partnerCore, $apiCore);
    }

    private function coreSlug(string $slug): string
    {
        $tokens = [];

        foreach (explode('-', $slug) as $token) {
            if ($token === '' || is_numeric($token) || in_array($token, self::CORE_STOP_TOKENS, true)) {
                continue;
            }

            $tokens[] = $token;
        }

        return implode('-', $tokens);
    }

    private function fuzzyCoreMatch(string $partnerCore, string $apiCore): bool
    {
        $partnerCompact = $this->softCompact($partnerCore);
        $apiCompact = $this->softCompact($apiCore);

        if (strlen($partnerCompact) < self::MIN_FUZZY_COMPACT_LENGTH
            || strlen($apiCompact) < self::MIN_FUZZY_COMPACT_LENGTH
        ) {
            return false;
        }

        if ($partnerCompact === $apiCompact) {
            return true;
        }

        $compactShorter = strlen($apiCompact) <= strlen($partnerCompact) ? $apiCompact : $partnerCompact;
        $compactLonger = $compactShorter === $apiCompact ? $partnerCompact : $apiCompact;

        if (strlen($compactShorter) >= self::MIN_FUZZY_COMPACT_LENGTH
            && str_contains($compactLonger, $compactShorter)
        ) {
            return true;
        }

        // Near-miss brands (Metcure vs MeCure) often share ~90% similarity; require a
        // shared prefix so single-letter edits early in the name do not match.
        if (! $this->sharesBrandPrefix($partnerCompact, $apiCompact)) {
            return false;
        }

        similar_text($partnerCompact, $apiCompact, $percent);

        if ($percent < self::FUZZY_SIMILAR_TEXT_PERCENT) {
            return false;
        }

        $maxLen = max(strlen($partnerCompact), strlen($apiCompact));
        $maxAllowed = max(1, (int) floor($maxLen * self::FUZZY_LEVENSHTEIN_RATIO));

        return levenshtein($partnerCompact, $apiCompact) <= $maxAllowed;
    }

    /**
     * Join hyphenated core tokens, collapsing a duplicated boundary letter
     * (tekt-tone → tektone, zen-skin → zenskin).
     */
    private function softCompact(string $core): string
    {
        $out = '';

        foreach (explode('-', $core) as $token) {
            if ($token === '') {
                continue;
            }

            if ($out !== '' && str_ends_with($out, $token[0])) {
                $out .= substr($token, 1);
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    private function sharesBrandPrefix(string $a, string $b): bool
    {
        $prefixLen = min(4, strlen($a), strlen($b));

        if ($prefixLen < 4) {
            return false;
        }

        return str_starts_with($a, substr($b, 0, $prefixLen))
            || str_starts_with($b, substr($a, 0, $prefixLen));
    }

    private function firstSignificantToken(string $slug): ?string
    {
        foreach (explode('-', $slug) as $token) {
            if ($token === '' || is_numeric($token) || in_array($token, self::CORE_STOP_TOKENS, true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function score(array $result): float
    {
        $score = 0.0;

        if ($result['verified'] ?? false) {
            $score += self::VERIFIED_POINTS;
        }

        $categories = $this->categories($result);

        $matchedPreferred = [];
        foreach ($categories as $category) {
            foreach (self::PREFERRED_CATEGORIES as $preferred) {
                if (str_contains($category, $preferred) && ! in_array($preferred, $matchedPreferred, true)) {
                    $matchedPreferred[] = $preferred;
                }
            }
        }
        $score += min(count($matchedPreferred), self::PREFERRED_CATEGORY_CAP) * self::PREFERRED_CATEGORY_POINTS;

        foreach ($categories as $category) {
            foreach (self::DEMOTED_KEYWORDS as $demoted) {
                if (str_contains($category, $demoted)) {
                    $score -= self::DEMOTION_PENALTY;

                    break 2;
                }
            }
        }

        if (filled($result['website'] ?? null)) {
            $score += self::WEBSITE_POINTS;
        }

        $reviewCount = (float) ($result['review_count'] ?? 0);
        $score += min($reviewCount / self::REVIEW_COUNT_DIVISOR, self::REVIEW_COUNT_CAP);

        return $score;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string> normalized, lowercase category strings from type/subtypes/subtype_gcids
     */
    private function categories(array $result): array
    {
        $raw = [];

        if (filled($result['type'] ?? null)) {
            $raw[] = (string) $result['type'];
        }

        foreach (['subtypes', 'subtype_gcids'] as $key) {
            $value = $result[$key] ?? [];
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (is_string($item) && $item !== '') {
                        $raw[] = $item;
                    }
                }
            }
        }

        return array_map(
            fn (string $category): string => strtolower(trim(str_replace(['gcid:', '_'], ['', ' '], $category))),
            $raw
        );
    }
}
