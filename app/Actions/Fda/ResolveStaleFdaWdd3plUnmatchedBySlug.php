<?php

namespace App\Actions\Fda;

use App\Enums\PartnerType;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWdd3plUnmatched;
use App\Support\Fda\FdaOrganizationSlugIndex;
use App\Support\Fda\FdaWdd3plDataset;
use App\Support\PartnerSlug;

/**
 * Close open WDD/3PL unmatched rows whose facility slug now matches an
 * fda_organizations row. Optionally fill null partner_type from FDA Type.
 */
final class ResolveStaleFdaWdd3plUnmatchedBySlug
{
    public function __construct(
        private readonly FdaWdd3plDataset $dataset,
    ) {}

    /**
     * @return array{
     *     scanned: int,
     *     linked: int,
     *     partner_type_filled: int,
     *     skipped: int,
     *     samples: list<string>
     * }
     */
    public function handle(?string $reportPath = null, bool $dryRun = false): array
    {
        $slugIndex = FdaOrganizationSlugIndex::map();
        $typeByFacility = $this->facilityTypeMajority($reportPath);

        $scanned = 0;
        $linked = 0;
        $partnerTypeFilled = 0;
        $skipped = 0;
        /** @var list<string> $samples */
        $samples = [];

        $open = FdaWdd3plUnmatched::query()
            ->unresolved()
            ->orderBy('id')
            ->get();

        foreach ($open as $row) {
            $scanned++;

            $slug = filled($row->slug_attempt)
                ? (string) $row->slug_attempt
                : PartnerSlug::from((string) $row->facility_name);

            $orgId = $slugIndex[$slug] ?? null;

            if ($orgId === null) {
                $skipped++;

                continue;
            }

            $org = FdaOrganization::query()->find($orgId);

            if (! $org instanceof FdaOrganization) {
                $skipped++;

                continue;
            }

            $this->pushSample(
                $samples,
                (string) $row->facility_name.' → #'.$org->id.' '.$org->canonical_name,
            );

            if ($dryRun) {
                $linked++;

                continue;
            }

            $type = $typeByFacility[(string) $row->facility_name] ?? null;

            if ($org->partner_type === null && $type !== null) {
                $org->forceFill(['partner_type' => $type])->save();
                $partnerTypeFilled++;
            }

            $row->forceFill([
                'fda_organization_id' => $org->id,
                'resolved_at' => now(),
            ])->save();

            $linked++;
        }

        return [
            'scanned' => $scanned,
            'linked' => $linked,
            'partner_type_filled' => $dryRun ? 0 : $partnerTypeFilled,
            'skipped' => $skipped,
            'samples' => $samples,
        ];
    }

    /**
     * @return array<string, PartnerType>
     */
    private function facilityTypeMajority(?string $reportPath): array
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
            $counts[$name] ??= ['wdd' => 0, 'tpl' => 0];

            if ($type === '3PL') {
                $counts[$name]['tpl']++;
            } else {
                // WDD or unknown → count toward wholesaler majority
                $counts[$name]['wdd']++;
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
        if (count($samples) >= 15) {
            return;
        }

        $samples[] = $line;
    }
}
