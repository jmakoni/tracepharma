<?php

namespace App\Actions\Fda;

use App\Enums\PartnerType;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWdd3plUnmatched;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\Fda\FdaOrganizationSlugIndex;
use App\Support\Fda\FdaWdd3plDataset;
use App\Support\Fda\OrganizationMatch;
use App\Support\Fda\OrganizationMatcher;
use App\Support\Fda\WddOrganizationName;
use App\Support\PartnerSlug;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Resolve remaining open WDD/3PL unmatched rows: roll DC site names up to a
 * parent organization, link when an org already exists, otherwise create one
 * org per parent with partner_type from FDA Type majority.
 */
final class ResolveOpenFdaWdd3plUnmatched
{
    public function __construct(
        private readonly FdaWdd3plDataset $dataset,
        private readonly OrganizationMatcher $matcher,
    ) {}

    /**
     * @return array{
     *     scanned: int,
     *     parents: int,
     *     linked: int,
     *     created: int,
     *     rows_resolved: int,
     *     samples: list<string>
     * }
     */
    public function handle(?string $reportPath = null, bool $dryRun = false): array
    {
        $typeByName = $this->typeMajorityByName($reportPath);
        $slugIndex = FdaOrganizationSlugIndex::map();

        $index = FdaOrganization::query()
            ->get(['id', 'canonical_name', 'duns_number'])
            ->map(static fn (FdaOrganization $org): array => [
                'id' => (int) $org->id,
                'canonical_name' => (string) $org->canonical_name,
                'duns_number' => $org->duns_number,
            ])
            ->values()
            ->all();

        /** @var Collection<string, Collection<int, FdaWdd3plUnmatched>> $groups */
        $groups = FdaWdd3plUnmatched::query()
            ->unresolved()
            ->orderBy('id')
            ->get()
            ->groupBy(fn (FdaWdd3plUnmatched $row): string => WddOrganizationName::fromFacilityName(
                (string) $row->facility_name
            ));

        $scanned = 0;
        $linked = 0;
        $created = 0;
        $rowsResolved = 0;
        /** @var list<string> $samples */
        $samples = [];

        foreach ($groups as $parentName => $rows) {
            if ($parentName === '') {
                continue;
            }

            $scanned += $rows->count();

            $org = $this->findExistingOrganization($parentName, $slugIndex, $index);
            $action = $org !== null ? 'link' : 'create';

            if ($org === null && ! $dryRun) {
                $org = $this->createOrganization(
                    $parentName,
                    $this->partnerTypeForGroup($parentName, $rows, $typeByName),
                );
                $index[] = [
                    'id' => (int) $org->id,
                    'canonical_name' => (string) $org->canonical_name,
                    'duns_number' => $org->duns_number,
                ];
                $slugIndex[PartnerSlug::from($parentName)] ??= (int) $org->id;
                $created++;
            } elseif ($org !== null) {
                $linked++;
            } elseif ($dryRun) {
                $created++;
            }

            $this->pushSample(
                $samples,
                $action.' '.$parentName.' ('.$rows->count().' rows)'
                    .($org !== null ? ' → #'.$org->id : ''),
            );

            if ($dryRun || $org === null) {
                continue;
            }

            foreach ($rows as $row) {
                $row->forceFill([
                    'fda_organization_id' => $org->id,
                    'resolved_at' => now(),
                ])->save();
                $rowsResolved++;
            }
        }

        return [
            'scanned' => $scanned,
            'parents' => $groups->count(),
            'linked' => $linked,
            'created' => $dryRun ? $created : $created,
            'rows_resolved' => $dryRun ? 0 : $rowsResolved,
            'samples' => $samples,
        ];
    }

    /**
     * @param  array<string, int>  $slugIndex
     * @param  list<array{id: int, canonical_name: string, duns_number: ?string}>  $index
     */
    private function findExistingOrganization(
        string $parentName,
        array $slugIndex,
        array $index,
    ): ?FdaOrganization {
        $slug = PartnerSlug::from($parentName);
        $orgId = $slugIndex[$slug] ?? null;

        if ($orgId !== null) {
            $org = FdaOrganization::query()->find($orgId);
            if ($org instanceof FdaOrganization) {
                return $org;
            }
        }

        $canonical = CompanyNameNormalizer::canonical($parentName);

        if ($canonical !== '') {
            $org = FdaOrganization::query()->where('canonical_name', $canonical)->orderBy('id')->first();
            if ($org instanceof FdaOrganization) {
                return $org;
            }
        }

        $match = $this->matcher->match($parentName, null, $index);

        if (
            $match->fdaOrganizationId !== null
            && in_array($match->action, [
                OrganizationMatch::ACTION_LINK,
                OrganizationMatch::ACTION_REVIEW,
            ], true)
        ) {
            $org = FdaOrganization::query()->find($match->fdaOrganizationId);
            if ($org instanceof FdaOrganization) {
                return $org;
            }
        }

        return null;
    }

    private function createOrganization(string $parentName, PartnerType $partnerType): FdaOrganization
    {
        $canonical = CompanyNameNormalizer::canonical($parentName) ?: $parentName;

        try {
            return FdaOrganization::query()->create([
                'original_name' => $parentName,
                'canonical_name' => $canonical,
                'name' => $parentName,
                'partner_type' => $partnerType,
                'is_active' => true,
            ]);
        } catch (QueryException $exception) {
            $existing = FdaOrganization::query()->where('canonical_name', $canonical)->orderBy('id')->first();

            if ($existing instanceof FdaOrganization) {
                if ($existing->partner_type === null) {
                    $existing->fillFromFda(['partner_type' => $partnerType]);
                }

                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * @param  Collection<int, FdaWdd3plUnmatched>  $rows
     * @param  array<string, PartnerType>  $typeByName
     */
    private function partnerTypeForGroup(
        string $parentName,
        Collection $rows,
        array $typeByName,
    ): PartnerType {
        if (isset($typeByName[$parentName])) {
            return $typeByName[$parentName];
        }

        $wdd = 0;
        $tpl = 0;

        foreach ($rows as $row) {
            $type = $typeByName[(string) $row->facility_name] ?? null;

            if ($type === PartnerType::Logistics3pl) {
                $tpl++;
            } else {
                $wdd++;
            }
        }

        return $tpl > $wdd ? PartnerType::Logistics3pl : PartnerType::Wholesaler;
    }

    /**
     * Keys by both raw Facility_Name and stripped parent name.
     *
     * @return array<string, PartnerType>
     */
    private function typeMajorityByName(?string $reportPath): array
    {
        $path = $this->dataset->resolvePath(
            $reportPath !== null && $reportPath !== '' ? $reportPath : null,
            false,
        );

        /** @var array<string, array{wdd: int, tpl: int}> $counts */
        $counts = [];

        foreach ($this->dataset->eachRow($path) as $row) {
            $name = trim((string) ($row['Facility_Name'] ?? ''));

            if ($name === '') {
                $name = trim((string) ($row['Doing_Business_As'] ?? ''));
            }

            if ($name === '') {
                continue;
            }

            $type = strtoupper(trim((string) ($row['Type'] ?? '')));
            $parent = WddOrganizationName::fromFacilityName($name);

            foreach (array_unique([$name, $parent]) as $key) {
                $counts[$key] ??= ['wdd' => 0, 'tpl' => 0];

                if ($type === '3PL') {
                    $counts[$key]['tpl']++;
                } else {
                    $counts[$key]['wdd']++;
                }
            }
        }

        $out = [];

        foreach ($counts as $name => $tally) {
            $out[$name] = $tally['tpl'] > $tally['wdd']
                ? PartnerType::Logistics3pl
                : PartnerType::Wholesaler;
        }

        return $out;
    }

    /**
     * @param  list<string>  $samples
     */
    private function pushSample(array &$samples, string $line): void
    {
        if (count($samples) >= 20) {
            return;
        }

        $samples[] = $line;
    }
}
