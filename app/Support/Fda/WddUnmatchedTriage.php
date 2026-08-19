<?php

namespace App\Support\Fda;

use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWdd3plUnmatched;
use App\Support\Catalog\DisplayName;
use App\Support\PartnerSlug;
use Illuminate\Database\Eloquent\Builder;

/**
 * Proposals for the WDD/3PL unmatched queue.
 *
 * Triage is a judgement call an admin makes row by row, so the queue offers what
 * the report already says — the FDA Type — and the organizations whose names sit
 * in the same family, which is where near-duplicates come from ("Cardinal Health
 * 110, LLC" against a catalog holding "Cardinal Health").
 */
final class WddUnmatchedTriage
{
    /**
     * Beyond this many candidates the list stops being a shortlist.
     */
    private const SUGGESTION_LIMIT = 10;

    public static function proposedPartnerType(FdaWdd3plUnmatched $record): PartnerType
    {
        return $record->facility_type === FacilityType::ThreePl
            ? PartnerType::Logistics3pl
            : PartnerType::Wholesaler;
    }

    /**
     * @return array<int, string> Organization id => display name
     */
    public static function nearDuplicateOrganizations(FdaWdd3plUnmatched $record): array
    {
        $family = self::slugFamily(
            WddOrganizationName::fromFacilityName((string) $record->facility_name),
        );

        if ($family === null) {
            return [];
        }

        return FdaOrganization::query()
            ->where(function (Builder $query) use ($family): void {
                $query->where('canonical_name', 'like', $family.'%')
                    ->orWhere('name', 'like', $family.'%')
                    ->orWhere('original_name', 'like', $family.'%');
            })
            ->orderBy('name')
            ->limit(self::SUGGESTION_LIMIT)
            ->get(['id', 'name', 'canonical_name'])
            ->mapWithKeys(fn (FdaOrganization $organization): array => [
                (int) $organization->id => DisplayName::clean($organization->name)
                    ?? (string) $organization->canonical_name,
            ])
            ->all();
    }

    /**
     * The leading slug segment, long enough to be a name family rather than a
     * prefix half the register shares.
     */
    private static function slugFamily(string $name): ?string
    {
        $head = explode('-', PartnerSlug::from($name))[0] ?? '';

        return strlen($head) >= 4 ? $head : null;
    }
}
