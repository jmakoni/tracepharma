<?php

namespace App\Support\MasterData;

use App\Models\Fda\FdaOrganization;
use App\Models\TradingPartner;
use Illuminate\Support\Collection;

final class MajorWholesalers
{
    public const SENTINEL_PREFIX = 'fda_wholesaler:';

    public const LEGACY_SENTINEL_PREFIX = 'catalog_wholesaler:';

    /**
     * FDA WDD/3PL legal-entity slug prefixes that belong to a Top-6 wholesaler.
     *
     * The report names the licensed entity and its branch ("McKesson Corporation
     * (Anchorage)", "Cardinal Health 110, LLC"), which slugs to something no
     * catalog organization carries, so licenses for the Top 6 never landed.
     * Matched as prefixes on the partner slug, longest first.
     *
     * @var array<string, string>
     */
    private const SLUG_ALIAS_PREFIXES = [
        'mckesson-corp' => 'mckesson',
        'mckesson-drug-co' => 'mckesson',
        'cardinal-health' => 'cardinal-health',
        'cencora' => 'cencora',
        'amerisourcebergen-drug' => 'cencora',
        'anda-inc' => 'anda',
        'morris-dickson' => 'morris-dickson',
        'smith-drug' => 'smith-drug',
        'j-m-smith' => 'smith-drug',
    ];

    /** @return list<array{slug: string, name: string, gln: string}> */
    public static function definitions(): array
    {
        return [
            ['slug' => 'mckesson', 'name' => 'McKesson', 'gln' => '0300000000001'],
            ['slug' => 'cardinal-health', 'name' => 'Cardinal Health', 'gln' => '0300000000002'],
            ['slug' => 'cencora', 'name' => 'Cencora', 'gln' => '0300000000003'],
            ['slug' => 'anda', 'name' => 'Anda', 'gln' => '0300000000004'],
            ['slug' => 'morris-dickson', 'name' => 'Morris & Dickson', 'gln' => '0300000000005'],
            ['slug' => 'smith-drug', 'name' => 'Smith Drug', 'gln' => '0300000000006'],
        ];
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_column(self::definitions(), 'slug');
    }

    /**
     * The Top-6 slug a WDD/3PL entity slug rolls up to, or null when the name is
     * not one of theirs. An exact Top-6 slug maps to itself.
     */
    public static function canonicalSlug(string $slug): ?string
    {
        $slug = trim(strtolower($slug));

        if ($slug === '') {
            return null;
        }

        if (in_array($slug, self::slugs(), true)) {
            return $slug;
        }

        $prefixes = self::SLUG_ALIAS_PREFIXES;
        uksort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($prefixes as $prefix => $canonical) {
            if ($slug === $prefix || str_starts_with($slug, $prefix.'-')) {
                return $canonical;
            }
        }

        return null;
    }

    public static function sentinel(int $fdaOrganizationId): string
    {
        return self::SENTINEL_PREFIX.$fdaOrganizationId;
    }

    public static function catalogIdFromSentinel(mixed $value): ?int
    {
        if (! self::isSentinel($value)) {
            return null;
        }

        $value = (string) $value;
        $prefix = str_starts_with($value, self::SENTINEL_PREFIX)
            ? self::SENTINEL_PREFIX
            : self::LEGACY_SENTINEL_PREFIX;
        $id = substr($value, strlen($prefix));

        if ($id === '' || ! ctype_digit($id)) {
            return null;
        }

        return (int) $id;
    }

    public static function isSentinel(mixed $value): bool
    {
        return is_string($value)
            && (str_starts_with($value, self::SENTINEL_PREFIX)
                || str_starts_with($value, self::LEGACY_SENTINEL_PREFIX));
    }

    /** @return Collection<int, FdaOrganization> */
    public static function fdaOrganizations(): Collection
    {
        $glns = array_column(self::definitions(), 'gln');

        if ($glns === []) {
            return collect();
        }

        return FdaOrganization::query()
            ->whereIn('gln', $glns)
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (FdaOrganization $org): int => array_search($org->gln, $glns, true) ?: 0)
            ->values();
    }

    public static function isMajorCatalogId(?int $fdaOrganizationId): bool
    {
        if ($fdaOrganizationId === null) {
            return false;
        }

        return in_array($fdaOrganizationId, self::fdaOrganizations()->pluck('id')->all(), true);
    }

    /** @return list<int> */
    public static function authorizedMajorCatalogIds(): array
    {
        $fdaIds = self::fdaOrganizations()->pluck('id')->all();

        if ($fdaIds === []) {
            return [];
        }

        return TradingPartner::query()
            ->where('is_active', true)
            ->whereIn('fda_organization_id', $fdaIds)
            ->pluck('fda_organization_id')
            ->unique()
            ->values()
            ->all();
    }

    public static function hasAnyAuthorizedMajor(): bool
    {
        return self::authorizedMajorCatalogIds() !== [];
    }
}
