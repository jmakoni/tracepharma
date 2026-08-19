<?php

namespace App\Support\Exceptions;

use App\Enums\PartnerType;
use App\Filament\App\Resources\FdaProducts\FdaProductResource;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Fda\FdaProductPackaging;
use App\Models\TradingPartner;
use App\Support\Catalog\DisplayName;
use App\Support\Gs1\Gtin;
use App\Support\Gs1\Ndc;

/**
 * Catalog / FDA assortment helpers for UNKNOWN_GTIN exception correction.
 */
final class AssortmentFromCatalog
{
    /**
     * Per-request memo for receive-from partner option lists.
     *
     * @var array<string, array<int|string, string>>
     */
    private static array $receiveFromPartnerOptionsCache = [];

    public static function normalizeGtinDigits(string $gtin): string
    {
        return preg_replace('/\D+/', '', $gtin) ?? '';
    }

    /**
     * @return list<string>
     */
    public static function gtinLookupCandidates(string $gtin): array
    {
        $digits = self::normalizeGtinDigits($gtin);

        if ($digits === '') {
            return [];
        }

        $candidates = [$digits];

        if (strlen($digits) >= 8 && strlen($digits) <= 14) {
            $candidates[] = str_pad($digits, 14, '0', STR_PAD_LEFT);
        }

        $fromUpc = Gtin::fromUpc($digits);

        if ($fromUpc !== null) {
            $candidates[] = $fromUpc;
        }

        return array_values(array_unique($candidates));
    }

    public static function findPackagingByGtin(string $gtin): ?FdaProductPackaging
    {
        foreach (self::gtinLookupCandidates($gtin) as $candidate) {
            $packaging = FdaProductPackaging::query()
                ->where('is_active', true)
                ->where('gtin', $candidate)
                ->with('product.fdaOrganization')
                ->first();

            if ($packaging !== null) {
                return $packaging;
            }
        }

        return self::findPackagingByReversedGtin($gtin);
    }

    private static function findPackagingByReversedGtin(string $gtin): ?FdaProductPackaging
    {
        foreach (self::gtinLookupCandidates($gtin) as $candidate) {
            $ndc10 = Gtin::ndc10FromNdcEncodedGtin($candidate);

            if ($ndc10 === null) {
                continue;
            }

            $candidates = self::reversedNdcSearchCandidates($ndc10);

            if ($candidates === []) {
                continue;
            }

            $packaging = FdaProductPackaging::query()
                ->where('is_active', true)
                ->where(function ($query) use ($candidates): void {
                    $query->whereIn('package_ndc', $candidates)
                        ->orWhereIn('ndc11', $candidates);
                })
                ->orderBy('id')
                ->with('product.fdaOrganization')
                ->first();

            if ($packaging !== null) {
                return $packaging;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function reversedNdcSearchCandidates(string $ndc10): array
    {
        // A bare 10-digit NDC does not say which segment was padded, so search every
        // spelling rather than assuming the labeler was the short one.
        $candidates = [$ndc10];

        foreach (Ndc::ndc11CandidatesFromTenDigits($ndc10) as $ndc11) {
            $candidates[] = $ndc11;
            foreach (Ndc::packageNdcCandidates($ndc11) as $packageNdc) {
                $candidates[] = $packageNdc;
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Active manufacturer / wholesaler / 3PL partners for receive-from selection.
     *
     * @return array<int|string, string>
     */
    public static function receiveFromPartnerOptions(?FdaProductPackaging $match): array
    {
        $cacheKey = $match === null ? 'none' : 'p:'.(string) $match->getKey();
        if (isset(self::$receiveFromPartnerOptionsCache[$cacheKey])) {
            return self::$receiveFromPartnerOptionsCache[$cacheKey];
        }

        $partners = TradingPartner::query()
            ->where('is_active', true)
            ->whereIn('partner_type', [
                PartnerType::Manufacturer,
                PartnerType::Wholesaler,
                PartnerType::Logistics3pl,
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'partner_type', 'fda_organization_id']);

        if ($partners->isEmpty()) {
            return self::$receiveFromPartnerOptionsCache[$cacheKey] = [];
        }

        $organizationId = self::matchOrganizationId($match);

        $labelerManufacturer = $partners->first(function (TradingPartner $partner) use ($organizationId): bool {
            return $organizationId !== null && (int) $partner->fda_organization_id === $organizationId;
        });

        $options = [];

        if ($labelerManufacturer !== null) {
            $name = DisplayName::clean($labelerManufacturer->name) ?: 'Partner';
            $options[$labelerManufacturer->getKey()] = "{$name} (Manufacturer)";
        }

        foreach ($partners as $partner) {
            if ($labelerManufacturer !== null && $partner->is($labelerManufacturer)) {
                continue;
            }

            $name = DisplayName::clean($partner->name) ?: 'Partner';
            $type = $partner->partner_type?->label() ?? 'Partner';
            $options[$partner->getKey()] = "{$name} ({$type})";
        }

        return self::$receiveFromPartnerOptionsCache[$cacheKey] = $options;
    }

    public static function preferredPartnerId(ExceptionCase $case, ?FdaProductPackaging $match): ?int
    {
        $organizationId = self::matchOrganizationId($match);

        if ($organizationId !== null) {
            $labelerPartnerId = TradingPartner::query()
                ->where('is_active', true)
                ->where('fda_organization_id', $organizationId)
                ->orderBy('id')
                ->value('id');

            if ($labelerPartnerId !== null) {
                return (int) $labelerPartnerId;
            }
        }

        $case->loadMissing('document');

        foreach ([$case->trading_partner_id, $case->document?->trading_partner_id] as $candidateId) {
            if ($candidateId === null) {
                continue;
            }

            $partner = TradingPartner::query()->find($candidateId);

            if ($partner === null || ! $partner->is_active) {
                continue;
            }

            // Wholesaler / 3PL receive-from does not require manufacturer labeler match.
            if ($partner->partner_type !== PartnerType::Manufacturer) {
                return (int) $candidateId;
            }

            if (
                $organizationId === null
                || (int) $partner->fda_organization_id === $organizationId
            ) {
                return (int) $candidateId;
            }
        }

        return null;
    }

    public static function fdaProductsUrl(?string $search): string
    {
        $url = FdaProductResource::getUrl('index', panel: 'app');

        $term = filled($search) ? trim((string) $search) : null;

        if ($term === null || $term === '') {
            return $url;
        }

        foreach (self::gtinLookupCandidates($term) as $candidate) {
            $ndc10 = Gtin::ndc10FromNdcEncodedGtin($candidate);

            if ($ndc10 === null) {
                continue;
            }

            $term = Ndc::formatPackageDisplay($ndc10) ?? $term;

            break;
        }

        return $url.'?'.http_build_query(['search' => $term]);
    }

    /**
     * Human-readable catalog match summary. When the searched GTIN differs
     * from the catalog's stored GTIN (i.e. the match was found by reversing
     * the GTIN to its NDC and searching package NDC), note that explicitly.
     */
    public static function formatCatalogMatch(?FdaProductPackaging $match, ?string $searchedGtin = null): string
    {
        if ($match === null) {
            return '';
        }

        $listing = $match->relationLoaded('product') ? $match->product : $match->product()->first();
        $name = DisplayName::clean($listing?->name ?: $listing?->brand_name ?: $listing?->generic_name) ?: 'Package';
        $parts = [$name];

        if (filled($match->package_ndc)) {
            $parts[] = 'Package NDC '.$match->package_ndc;
        }

        if (filled($match->gtin)) {
            $parts[] = 'GTIN '.$match->gtin;
        }

        if (
            filled($searchedGtin)
            && filled($match->gtin)
            && ! in_array($match->gtin, self::gtinLookupCandidates($searchedGtin), true)
        ) {
            $parts[] = 'matched via package NDC (searched GTIN '.trim($searchedGtin).' differs)';
        }

        return implode(' · ', $parts);
    }

    public static function catalogMissMessage(): string
    {
        return 'This GTIN is not in FDA packaging. Open FDA Products to find the NDC and authorize packages from there — do not invent a freeform product record.';
    }

    private static function matchOrganizationId(?FdaProductPackaging $match): ?int
    {
        if ($match === null) {
            return null;
        }

        $listing = $match->relationLoaded('product') ? $match->product : $match->product()->first();

        return $listing?->fda_organization_id !== null ? (int) $listing->fda_organization_id : null;
    }
}
