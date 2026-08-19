<?php

namespace App\Support\Fda;

/**
 * Four-band FDA organization match: exact DUNS/canonical, high fuzzy auto-link,
 * ambiguous review, novel auto-create.
 *
 * @phpstan-type OrgCandidate array{id: int, canonical_name: string, duns_number: ?string}
 */
final class OrganizationMatcher
{
    public const HIGH_THRESHOLD = 92.0;

    /**
     * @param  list<OrgCandidate>  $existing
     * @param  bool  $strictIdentity  When true (DECRS): DUNS-first identity only.
     *                                Present DUNS that misses → CREATE (ignore name).
     *                                Missing DUNS → exact canonical only (no prefix/fuzzy).
     */
    public function match(
        string $originalName,
        ?string $duns,
        array $existing,
        bool $strictIdentity = false,
    ): OrganizationMatch {
        $canonical = CompanyNameNormalizer::canonical($originalName);
        $duns = self::normalizeDuns($duns);

        if ($duns !== null) {
            foreach ($existing as $org) {
                if (self::normalizeDuns($org['duns_number'] ?? null) === $duns) {
                    return new OrganizationMatch(
                        OrganizationMatch::ACTION_LINK,
                        $org['id'],
                        100.0,
                        'duns'
                    );
                }
            }

            if ($strictIdentity) {
                return new OrganizationMatch(OrganizationMatch::ACTION_CREATE, null, 0.0, 'novel');
            }
        }

        if ($canonical !== '') {
            foreach ($existing as $org) {
                if (self::candidateCanonical($org) === $canonical) {
                    return new OrganizationMatch(
                        OrganizationMatch::ACTION_LINK,
                        $org['id'],
                        100.0,
                        'canonical_name'
                    );
                }
            }

            if ($strictIdentity) {
                return new OrganizationMatch(OrganizationMatch::ACTION_CREATE, null, 0.0, 'novel');
            }

            $prefixMatch = self::longestUniquePrefix($canonical, $existing);
            if ($prefixMatch !== null) {
                return $prefixMatch;
            }
        }

        if ($canonical === '' || $existing === []) {
            return new OrganizationMatch(OrganizationMatch::ACTION_CREATE, null, 0.0, 'novel');
        }

        // Fuzzy scoring runs only when names share a distinctive brand token
        // (strlen >= 4, not a stopword). Industry-only overlap must not open a review.
        // High fuzzy also requires matching leading tokens. Mid-band REVIEW was
        // removed: "proper extension" false-positives merge distinct siblings
        // (Owell Naturals vs Owell Naturals Brand, Lukang vs Lukang Shelile, etc.).
        $queryTokens = self::distinctiveTokens($canonical);
        $queryLeading = self::leadingToken($canonical);
        $candidates = self::tokenOverlapCandidatesFromTokens($queryTokens, $existing);

        if ($candidates === []) {
            return new OrganizationMatch(OrganizationMatch::ACTION_CREATE, null, 0.0, 'novel');
        }

        $highHits = [];

        foreach ($candidates as $org) {
            $candCanonical = self::candidateCanonical($org);

            if ($queryLeading === null || $queryLeading !== self::leadingToken($candCanonical)) {
                continue;
            }

            similar_text($canonical, $candCanonical, $percent);

            if ($percent >= self::HIGH_THRESHOLD) {
                $highHits[] = ['org' => $org, 'percent' => $percent];
            }
        }

        usort($highHits, static fn (array $a, array $b): int => $b['percent'] <=> $a['percent']);

        if (count($highHits) === 1) {
            return new OrganizationMatch(
                OrganizationMatch::ACTION_LINK,
                $highHits[0]['org']['id'],
                $highHits[0]['percent'],
                'high_fuzzy'
            );
        }

        if (count($highHits) > 1) {
            $best = $highHits[0];

            return new OrganizationMatch(
                OrganizationMatch::ACTION_REVIEW,
                $best['org']['id'] ?? null,
                $best['percent'] ?? null,
                'ambiguous_high'
            );
        }

        return new OrganizationMatch(OrganizationMatch::ACTION_CREATE, null, 0.0, 'novel');
    }

    public static function normalizeDuns(?string $duns): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $duns) ?? '';

        if ($digits === '') {
            return null;
        }

        return str_pad($digits, 9, '0', STR_PAD_LEFT);
    }

    /**
     * @param  list<OrgCandidate>  $existing
     */
    private static function longestUniquePrefix(string $canonical, array $existing): ?OrganizationMatch
    {
        $best = [];
        $maxLen = 0;

        foreach ($existing as $org) {
            $name = self::candidateCanonical($org);

            if ($name === '' || ! str_starts_with($canonical, $name.' ')) {
                continue;
            }

            // Do not treat country/geo subsidiaries as extensions of a shorter
            // brand (Fresenius Kabi Austria ↛ Fresenius Kabi / Fresenius Kabi US).
            $remainder = trim(substr($canonical, strlen($name) + 1));
            if (self::remainderHasGeoToken($remainder)) {
                continue;
            }

            $len = strlen($name);

            if ($len > $maxLen) {
                $maxLen = $len;
                $best = [$org];
            } elseif ($len === $maxLen) {
                $best[] = $org;
            }
        }

        if ($best === []) {
            return null;
        }

        if (count($best) > 1) {
            return new OrganizationMatch(
                OrganizationMatch::ACTION_REVIEW,
                $best[0]['id'],
                99.0,
                'ambiguous_prefix'
            );
        }

        return new OrganizationMatch(
            OrganizationMatch::ACTION_LINK,
            $best[0]['id'],
            99.0,
            'canonical_prefix'
        );
    }

    /**
     * Country / region tokens that mark a distinct subsidiary when left after a
     * shorter brand prefix.
     *
     * @var list<string>
     */
    private const GEO_PREFIX_BLOCKERS = [
        'AUSTRIA', 'AUT', 'GERMANY', 'DEUTSCHLAND', 'CHINA', 'IRELAND', 'FRANCE',
        'BELGIUM', 'ITALY', 'ITALIANA', 'SWEDEN', 'CANADA', 'JAPAN', 'KOREA',
        'INDIA', 'BRAZIL', 'MEXICO', 'SPAIN', 'NETHERLANDS', 'SWITZERLAND',
        'AUSTRALIA', 'POLAND', 'DENMARK', 'NORWAY', 'FINLAND', 'PORTUGAL',
        'GREECE', 'TURKEY', 'TAIWAN', 'SINGAPORE', 'THAILAND', 'MALAYSIA',
        'INDONESIA', 'PHILIPPINES', 'VIETNAM', 'ISRAEL', 'EGYPT', 'ARGENTINA',
        'CHILE', 'COLOMBIA', 'PERU', 'RUSSIA', 'UKRAINE', 'CZECH', 'HUNGARY',
        'ROMANIA', 'SLOVAKIA', 'CROATIA', 'SERBIA', 'BULGARIA', 'LITHUANIA',
        'LATVIA', 'ESTONIA', 'ICELAND', 'NEWZEALAND', 'HONGKONG', 'MACAU',
        'PUERTO', 'RICO', 'SCOTLAND', 'WALES', 'ENGLAND', 'ALTKIRCH',
        'SHIJIAZHUANG', 'SHANGHAI', 'BEIJING', 'GUANGZHOU', 'SHENZHEN',
    ];

    private static function remainderHasGeoToken(string $remainder): bool
    {
        if ($remainder === '') {
            return false;
        }

        foreach (preg_split('/\s+/', $remainder) ?: [] as $token) {
            if ($token !== '' && in_array($token, self::GEO_PREFIX_BLOCKERS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<OrgCandidate>  $existing
     * @return list<OrgCandidate>
     */
    private static function tokenOverlapCandidates(string $canonical, array $existing): array
    {
        return self::tokenOverlapCandidatesFromTokens(self::distinctiveTokens($canonical), $existing);
    }

    /**
     * @param  list<string>  $queryTokens
     * @param  list<OrgCandidate>  $existing
     * @return list<OrgCandidate>
     */
    private static function tokenOverlapCandidatesFromTokens(array $queryTokens, array $existing): array
    {
        if ($queryTokens === []) {
            return [];
        }

        $candidates = [];

        foreach ($existing as $org) {
            $orgTokens = self::distinctiveTokens(self::candidateCanonical($org));

            if (array_intersect($queryTokens, $orgTokens) !== []) {
                $candidates[] = $org;
            }
        }

        return $candidates;
    }

    /**
     * Re-canonicalize stored names so legacy "… COLTD" rows match current rules.
     *
     * @param  OrgCandidate  $org
     */
    private static function candidateCanonical(array $org): string
    {
        $stored = (string) ($org['canonical_name'] ?? '');

        if ($stored === '') {
            return '';
        }

        $normalized = CompanyNameNormalizer::canonical($stored);

        return $normalized !== '' ? $normalized : $stored;
    }

    /**
     * Generic legal/industry words that must not drive a fuzzy match on their own.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'PHARMA', 'PHARM', 'PHARMACEUTICAL', 'PHARMACEUTICALS',
        'LAB', 'LABS', 'LABORATORY', 'LABORATORIES',
        'HEALTH', 'HEALTHCARE', 'MEDICAL', 'MEDICINE',
        'US', 'USA', 'UK', 'EU',
        'GROUP', 'HOLDING', 'HOLDINGS', 'INTERNATIONAL', 'GLOBAL',
        'INDUSTRIES', 'INDUSTRY', 'INDUSTRIE', 'PRODUCTS', 'PRODUCT',
        'SUPPLY', 'SUPPLIES', 'MANUFACTURING', 'MANUFACTURER',
        'THERAPEUTICS', 'BIOSCIENCES', 'SCIENCES', 'SPECIALTIES', 'SPECIALTY',
        'CHEMICAL', 'CHEMICALS', 'ANIMAL', 'HUMAN',
        'DIV', 'DIVISION',
        'MARKETING', 'PRESCRIPTION', 'CENTER', 'CENTRE', 'CENTERS', 'CENTRES',
        'CRITICAL', 'CARE', 'SOLUTIONS', 'SERVICES', 'PHARMACY',
        'DISTRIBUTION', 'DISTRIBUTING', 'DISTRIBUTORS', 'WHOLESALE', 'WHOLESALER',
        'LOGISTICS', 'LOGISTICAL', 'RESOURCES',
        'REGIONAL', 'CONSULTING', 'FOUNDATION', 'HOSPITAL', 'HOSPITALS',
        'AMERICA', 'AMERICAN', 'TECHNOLOGY', 'TECHNOLOGIES',
        'ENTERPRISE', 'ENTERPRISES', 'DENTAL', 'SURGICAL',
        'DRUG', 'DRUGS', 'STORES', 'STORE', 'TRADING', 'TRADE',
        'CHILDREN', 'CHILDRENS', 'CHILD', 'COMPANIES', 'COMPANY',
        'LINK', 'NETWORK', 'NETWORKS', 'ASSOCIATES', 'ASSOCIATION',
        'SYSTEM', 'SYSTEMS', 'INSTITUTE', 'INSTITUTES',
        'COUNTY', 'MEMORIAL', 'COMMUNITY', 'BLOOD',
        'BIOTECHNOLOGY', 'BIOTECH', 'COSMETICS', 'COSMETIC',
        'HOME', 'EQUIPMENT', 'RESPIRATORY', 'PARTNERS', 'PARTNER',
        'ORGANICS', 'PRIVATE', 'LIMITED', 'PLASTIC', 'SURGERY',
        'PAIN', 'RELIEF', 'PLANT', 'GMBH', 'CYLINDER', 'GASES', 'GAS',
        'FINE', 'CHEM', 'RADIOCHEMISTRY', 'FACILITY', 'FACILITIES',
        'GERMANY', 'OXYGEN', 'ECOMMERCE', 'COMMERCE',
        'GUANGDONG', 'GUANGZHOU', 'SHANTOU', 'NANJING', 'SICHUAN',
        'SHIJIAZHUANG', 'YUNLIN', 'SKIN', 'CLINIC',
    ];

    /**
     * First token with length >= 2 (legal fluff already stripped by canonicalization).
     */
    private static function leadingToken(string $canonical): ?string
    {
        foreach (preg_split('/\s+/', $canonical) ?: [] as $token) {
            if (strlen($token) >= 2) {
                return $token;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function distinctiveTokens(string $canonical): array
    {
        $tokens = preg_split('/\s+/', $canonical) ?: [];
        $out = [];

        foreach ($tokens as $token) {
            if (strlen($token) < 4 || in_array($token, self::STOPWORDS, true)) {
                continue;
            }

            $out[$token] = true;
        }

        return array_keys($out);
    }
}
