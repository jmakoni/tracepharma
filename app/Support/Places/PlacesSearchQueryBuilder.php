<?php

namespace App\Support\Places;

/**
 * Builds a short, ordered list of Places search queries for a trading partner
 * name so backfill can retry when the literal name returns no usable HQ.
 */
final class PlacesSearchQueryBuilder
{
    private const MAX_QUERIES = 4;

    private const MIN_VARIANT_LENGTH = 4;

    /**
     * Trailing legal-entity suffixes stripped from display names (loop until stable).
     *
     * @var list<string>
     */
    private const TRAILING_LEGAL_PATTERNS = [
        '/\s*,?\s*\bl\.?\s*l\.?\s*c\.?\b\.?\s*$/iu',
        '/\s*,?\s*\b(?:inc(?:orporated)?)\.?\s*$/iu',
        '/\s*,?\s*\b(?:corp(?:oration)?)\.?\s*$/iu',
        '/\s*,?\s*\b(?:ltd\.?|limited)\.?\s*$/iu',
        '/\s*,?\s*\b(?:co\.?|company)\.?\s*$/iu',
        '/\s*,?\s*\bp\.?\s*l\.?\s*c\.?\b\.?\s*$/iu',
        '/\s*,?\s*\bl\.?\s*l\.?\s*p\.?\b\.?\s*$/iu',
        '/\s*,?\s*\bp\.?\s*c\.?\b\.?\s*$/iu',
        '/\s*,?\s*\bs\.?\s*a\.?\s*e\.?\b\.?\s*$/iu',
        '/\s*,?\s*\bg\.?\s*m\.?\s*b\.?\s*h\.?\b\.?\s*$/iu',
        '/\s*,?\s*\ba\.?\s*g\.?\b\.?\s*$/iu',
        '/\s*,?\s*\bs\.?\s*a\.?\b\.?\s*$/iu',
        '/\s*,?\s*\b(?:n\.?\s*v\.?|b\.?\s*v\.?)\b\.?\s*$/iu',
        '/\s*,?\s*\b(?:s\.?\s*p\.?\s*a\.?|s\.?\s*r\.?\s*l\.?)\b\.?\s*$/iu',
        '/\s*,?\s*\bpty\.?\s*(?:ltd\.?)?\.?\s*$/iu',
        '/\s*,?\s*\b(?:k\.?\s*g\.?|k\.?\s*g\.?\s*a\.?\s*a\.?)\b\.?\s*$/iu',
    ];

    /**
     * @return list<string>
     */
    public function queries(string $partnerName): array
    {
        $original = trim($partnerName);
        $queries = [];

        $this->push($queries, $original);

        $stripped = $this->stripTrailingLegalSuffixes($original);
        $this->push($queries, $stripped);

        $alias = $this->parentheticalAlias($original);
        if ($alias !== null) {
            $this->push($queries, $alias);
        }

        $core = $stripped !== '' ? $stripped : $original;
        if (mb_strlen($core) >= self::MIN_VARIANT_LENGTH) {
            $this->push($queries, $core.' pharmaceutical');
            $this->push($queries, $core.' headquarters');
        }

        return array_slice($queries, 0, self::MAX_QUERIES);
    }

    /**
     * @param  list<string>  $queries
     */
    private function push(array &$queries, string $candidate): void
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $candidate) ?? $candidate);

        if (mb_strlen($normalized) < self::MIN_VARIANT_LENGTH) {
            return;
        }

        foreach ($queries as $existing) {
            if (strcasecmp($existing, $normalized) === 0) {
                return;
            }
        }

        $queries[] = $normalized;
    }

    private function stripTrailingLegalSuffixes(string $name): string
    {
        $current = trim($name);
        $current = preg_replace('/[,.\s]+$/u', '', $current) ?? $current;

        $guard = 0;
        while ($guard < 8) {
            $before = $current;

            foreach (self::TRAILING_LEGAL_PATTERNS as $pattern) {
                $current = trim(preg_replace($pattern, '', $current) ?? $current);
                $current = preg_replace('/[,.\s]+$/u', '', $current) ?? $current;
            }

            if ($current === $before) {
                break;
            }

            $guard++;
        }

        return trim(preg_replace('/\s+/u', ' ', $current) ?? $current);
    }

    private function parentheticalAlias(string $name): ?string
    {
        if (! preg_match_all('/\(([^)]+)\)/u', $name, $matches) || $matches[1] === []) {
            return null;
        }

        $alias = trim((string) end($matches[1]));

        if (mb_strlen($alias) < self::MIN_VARIANT_LENGTH) {
            return null;
        }

        return $alias;
    }
}
