<?php

namespace App\Actions\Fda;

use App\Enums\FacilityType;
use App\Models\Fda\FdaWdd3plImportRun;
use App\Models\Fda\FdaWdd3plUnmatched;
use App\Support\Fda\FdaDate;
use App\Support\Fda\FdaOrganizationSlugIndex;
use App\Support\Fda\FdaWdd3plDataset;
use App\Support\Fda\WddOrganizationName;
use App\Support\MasterData\MajorWholesalers;
use App\Support\PartnerSlug;
use Illuminate\Support\Facades\DB;

/**
 * Import the FDA WDD/3PL facilities TSV report into the fda_wdd_3pl_staging
 * table, matching each row to an existing FdaOrganization by name slug.
 * Rows that cannot be matched to a known organization are skipped.
 *
 * The report is too large to hold in one transaction, so each attempt opens an
 * {@see FdaWdd3plImportRun} and stamps it complete only once the whole file has been
 * streamed. Promotion reads that marker to tell a full snapshot from a truncated
 * table plus half a file.
 */
final class ImportFdaWdd3plStaging
{
    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly FdaWdd3plDataset $dataset,
        private readonly UpsertFdaWdd3plUnmatched $unmatchedUpserter,
    ) {}

    /**
     * @return array{
     *     read: int,
     *     matched: int,
     *     skipped_unmatched: int,
     *     inserted: int,
     *     unmatched_facilities: array<string, int>,
     *     unmatched_facility_types: array<string, string>,
     *     import_run_id: int
     * }
     */
    public function handle(string $path): array
    {
        $counts = [
            'read' => 0,
            'matched' => 0,
            'skipped_unmatched' => 0,
            'inserted' => 0,
            'unmatched_facilities' => [],
            'unmatched_facility_types' => [],
        ];

        /** @var array<string, array<string, int>> $typeTallies */
        $typeTallies = [];

        $run = FdaWdd3plImportRun::query()->create([
            'source_path' => $path,
            'sha256' => $this->fileHash($path),
            'started_at' => now(),
        ]);

        $orgIdsBySlug = FdaOrganizationSlugIndex::map();
        $resolvedUnmatched = $this->resolvedUnmatchedIndex();

        DB::table('fda_wdd_3pl_staging')->truncate();

        $buffer = [];

        foreach ($this->dataset->eachRow($path) as $row) {
            $counts['read']++;

            $organizationId = $this->matchOrganizationId($row, $orgIdsBySlug, $resolvedUnmatched);

            if ($organizationId === null) {
                $counts['skipped_unmatched']++;
                $label = $this->unmatchedFacilityLabel($row);

                if ($label !== null) {
                    $counts['unmatched_facilities'][$label] = ($counts['unmatched_facilities'][$label] ?? 0) + 1;

                    $type = $this->facilityType($row);

                    if ($type !== null) {
                        $typeTallies[$label][$type] = ($typeTallies[$label][$type] ?? 0) + 1;
                    }
                }

                continue;
            }

            $counts['matched']++;
            $buffer[] = $this->mapRow($row, $organizationId);

            if (count($buffer) >= self::CHUNK_SIZE) {
                DB::table('fda_wdd_3pl_staging')->insert($buffer);
                $counts['inserted'] += count($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table('fda_wdd_3pl_staging')->insert($buffer);
            $counts['inserted'] += count($buffer);
        }

        $counts['unmatched_facility_types'] = $this->majorityFacilityTypes($typeTallies);

        $this->unmatchedUpserter->handle(
            $counts['unmatched_facilities'],
            $counts['unmatched_facility_types'],
        );

        $run->forceFill([
            'rows_read' => $counts['read'],
            'rows_matched' => $counts['matched'],
            'rows_skipped_unmatched' => $counts['skipped_unmatched'],
            'row_count' => $counts['inserted'],
            'completed_at' => now(),
        ])->save();

        $counts['import_run_id'] = (int) $run->getKey();

        return $counts;
    }

    /**
     * Recorded so a re-import of a byte-identical file is recognisable; a file we
     * cannot read is not worth failing the import over.
     */
    private function fileHash(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $hash = hash_file('sha256', $path);

        return $hash === false ? null : $hash;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, int>  $idsBySlug
     * @param  array<string, int>  $resolvedUnmatched
     */
    private function matchOrganizationId(array $row, array $idsBySlug, array $resolvedUnmatched): ?int
    {
        foreach ([$row['Facility_Name'] ?? '', $row['Doing_Business_As'] ?? ''] as $name) {
            foreach ($this->slugCandidates((string) $name) as $slug) {
                $id = $idsBySlug[$slug]
                    ?? $resolvedUnmatched[$slug]
                    ?? $this->matchMajorWholesalerAlias($slug, $idsBySlug);

                if ($id !== null) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * The report names licensed entities and their branches ("Cardinal Health 110,
     * LLC"); a Top-6 wholesaler carries one organization row, so roll those
     * entity slugs up before giving up on the row.
     *
     * @param  array<string, int>  $idsBySlug
     */
    private function matchMajorWholesalerAlias(string $slug, array $idsBySlug): ?int
    {
        $canonical = MajorWholesalers::canonicalSlug($slug);

        return $canonical === null ? null : ($idsBySlug[$canonical] ?? null);
    }

    /**
     * Slugs a facility name can be known by: as written, and with the DC site
     * suffix stripped to the parent organization.
     *
     * @return list<string>
     */
    private function slugCandidates(string $name): array
    {
        $name = trim($name);

        if ($name === '') {
            return [];
        }

        $slugs = [];

        foreach ([$name, WddOrganizationName::fromFacilityName($name)] as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $slug = PartnerSlug::from($candidate);

            if (! in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * Facility names an admin already triaged, so a link or a create in the
     * unmatched queue matches on the next import without re-staging the rows.
     *
     * @return array<string, int>
     */
    private function resolvedUnmatchedIndex(): array
    {
        $map = [];

        FdaWdd3plUnmatched::query()
            ->resolved()
            ->whereNotNull('fda_organization_id')
            ->orderBy('id')
            ->get(['id', 'facility_name', 'slug_attempt', 'fda_organization_id'])
            ->each(function (FdaWdd3plUnmatched $row) use (&$map): void {
                $organizationId = (int) $row->fda_organization_id;

                $slugs = $this->slugCandidates((string) $row->facility_name);

                if (filled($row->slug_attempt)) {
                    $slugs[] = (string) $row->slug_attempt;
                }

                foreach ($slugs as $slug) {
                    $map[$slug] ??= $organizationId;
                }
            });

        return $map;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row, int $organizationId): array
    {
        $type = trim((string) ($row['Type'] ?? ''));
        $reportingYear = trim((string) ($row['Reporting_Year'] ?? ''));

        return [
            'fda_organization_id' => $organizationId,
            'facility_name' => $this->nullableString($row['Facility_Name'] ?? null),
            'alternate_name' => $this->nullableString($row['Doing_Business_As'] ?? null),
            'street_address' => $this->nullableString($row['Facility_Street'] ?? null),
            'city' => $this->nullableString($row['Facility_City'] ?? null),
            'state' => $this->normalizeStateCode($row['Facility_State'] ?? null),
            'zip' => $this->resolveZip($row),
            'contact_person' => $this->nullableString($row['Facility_Contact_Name'] ?? null),
            'contact_email' => $this->nullableString($row['Facility_Contact_Email'] ?? null),
            'contact_phone' => $this->nullableString($row['Facility_Contact_Phone'] ?? null),
            'facility_type' => $type !== '' ? strtolower($type) : null,
            'license_number' => $this->nullableString($row['License_Number'] ?? null),
            'license_state' => $this->normalizeStateCode($row['License_State'] ?? null),
            'expiration_date' => FdaDate::toDateString($this->nullableString($row['License_Expiration_Date'] ?? null)),
            'reporting_year' => ctype_digit($reportingYear) ? (int) $reportingYear : null,
        ];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function facilityType(array $row): ?string
    {
        $type = strtolower(trim((string) ($row['Type'] ?? '')));

        return FacilityType::tryFrom($type)?->value;
    }

    /**
     * The FDA Type most rows of a skipped facility carry, so triage can propose
     * one instead of asking.
     *
     * @param  array<string, array<string, int>>  $tallies
     * @return array<string, string>
     */
    private function majorityFacilityTypes(array $tallies): array
    {
        $types = [];

        foreach ($tallies as $label => $counts) {
            arsort($counts, SORT_NUMERIC);
            $type = array_key_first($counts);

            if ($type !== null) {
                $types[$label] = (string) $type;
            }
        }

        return $types;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function unmatchedFacilityLabel(array $row): ?string
    {
        $facilityName = $this->nullableString($row['Facility_Name'] ?? null);

        if ($facilityName !== null) {
            return WddOrganizationName::fromFacilityName($facilityName);
        }

        $dba = $this->nullableString($row['Doing_Business_As'] ?? null);

        return $dba !== null ? WddOrganizationName::fromFacilityName($dba) : null;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function resolveZip(array $row): ?string
    {
        foreach (['Facility_Zip', 'ZIP', 'Zip', 'zip'] as $column) {
            $value = $this->nullableString($row[$column] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Strip an optional "US-" country prefix (e.g. "US-TX") and take the
     * 2-letter state code.
     */
    private function normalizeStateCode(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'US-')) {
            $value = substr($value, 3);
        }

        return mb_substr($value, 0, 2);
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        // FDA TSV is Windows-1252; soft hyphens and other high bytes break utf8mb4 inserts.
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        $value = str_replace(["\u{00AD}", "\xAD"], '', $value);

        return $value === '' ? null : $value;
    }
}
