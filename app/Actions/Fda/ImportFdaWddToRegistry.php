<?php

namespace App\Actions\Fda;

use App\Enums\FacilityType;
use App\Models\Fda\FdaImportRun;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Support\Fda\AddressFingerprint;
use App\Support\Fda\FdaDate;
use App\Support\Fda\FdaWdd3plDataset;
use App\Support\Fda\OrganizationMatcher;
use App\Support\Fda\WddOrganizationName;
use App\Support\Places\UsState;

/**
 * Load the WDD/3PL TSV into Pure FDA facilities and licenses.
 * Staging import remains a separate action for the existing Admin UI.
 */
final class ImportFdaWddToRegistry
{
    public function __construct(
        private readonly FdaWdd3plDataset $dataset,
        private readonly ResolveFdaOrganization $orgResolver,
    ) {}

    /**
     * @return array{
     *     read: int,
     *     inserted: int,
     *     updated: int,
     *     skipped: int,
     *     sent_to_review: int,
     *     licenses_delisted: int,
     *     import_run_id: int
     * }
     */
    public function handle(string $path): array
    {
        $started = microtime(true);
        $run = FdaImportRun::query()->create([
            'source' => 'wdd',
            'source_path' => $path,
            'sha256' => $this->fileHash($path),
            'started_at' => now(),
        ]);

        $counts = [
            'read' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'sent_to_review' => 0,
            'licenses_delisted' => 0,
        ];

        $index = FdaOrganization::query()
            ->get(['id', 'canonical_name', 'duns_number'])
            ->map(static fn (FdaOrganization $org): array => [
                'id' => (int) $org->id,
                'canonical_name' => (string) $org->canonical_name,
                'duns_number' => $org->duns_number,
            ])
            ->values()
            ->all();

        /** @var array<int, true> $seenLicenseIds */
        $seenLicenseIds = [];

        foreach ($this->dataset->eachRow($path) as $row) {
            $counts['read']++;

            $orgName = $this->nullable($row['Facility_Name'] ?? null)
                ?? $this->nullable($row['Doing_Business_As'] ?? null);

            if ($orgName === null) {
                $counts['skipped']++;

                continue;
            }

            // Resolve parent company — DC site suffixes stay on the facility row only.
            $orgName = WddOrganizationName::fromFacilityName($orgName);

            $resolved = $this->orgResolver->handle(
                'wdd',
                $orgName,
                null,
                $index,
                [
                    'facility_name' => $row['Facility_Name'] ?? null,
                    'dba' => $row['Doing_Business_As'] ?? null,
                ]
            );

            if ($resolved['reviewed'] || $resolved['organization'] === null) {
                $counts['sent_to_review']++;

                continue;
            }

            $facility = $this->upsertFacility($row, $resolved['organization'], $counts);

            $license = $this->upsertLicense($row, $facility);
            if ($license !== null) {
                $seenLicenseIds[(int) $license->id] = true;
            }
        }

        if ($seenLicenseIds !== []) {
            $counts['licenses_delisted'] = FdaWddLicense::query()
                ->where('is_active', true)
                ->whereNotIn('id', array_keys($seenLicenseIds))
                ->where(function ($query): void {
                    $query->whereNull('manually_edited_fields')
                        ->orWhereRaw(
                            "NOT JSON_CONTAINS(COALESCE(manually_edited_fields, JSON_ARRAY()), ?)",
                            ['"is_active"'],
                        );
                })
                ->update(['is_active' => false]);
        }

        $run->forceFill([
            'rows_read' => $counts['read'],
            'rows_inserted' => $counts['inserted'],
            'rows_updated' => $counts['updated'],
            'rows_skipped' => $counts['skipped'],
            'rows_sent_to_review' => $counts['sent_to_review'],
            'completed_at' => now(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ])->save();

        $counts['import_run_id'] = (int) $run->getKey();

        return $counts;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array{read: int, inserted: int, updated: int, skipped: int, sent_to_review: int, licenses_delisted: int}  $counts
     */
    private function upsertFacility(array $row, FdaOrganization $org, array &$counts): FdaWddFacility
    {
        $type = strtolower(trim((string) ($row['Type'] ?? 'wdd')));
        $facilityType = $type === FacilityType::ThreePl->value
            ? FacilityType::ThreePl
            : FacilityType::Wdd;

        $street = $this->nullable($row['Facility_Street'] ?? null);
        $city = $this->nullable($row['Facility_City'] ?? null);
        $state = $this->normalizeState($row['Facility_State'] ?? null);
        $zip = $this->nullable($row['Facility_Zip'] ?? $row['ZIP'] ?? $row['Zip'] ?? $row['zip'] ?? null);
        $fingerprint = AddressFingerprint::fromWdd($street, $city, $state, $zip);

        $attributes = [
            'facility_name' => $this->nullable($row['Facility_Name'] ?? null),
            'alternate_name' => $this->nullable($row['Doing_Business_As'] ?? null),
            'street_address' => $street,
            'city' => $city,
            'state_province' => $state,
            'postal_code' => $zip,
            'country_code' => 'US',
            'full_address' => collect([$street, $city, $state, $zip])->filter()->implode(', '),
            'contact_person' => $this->nullable($row['Facility_Contact_Name'] ?? null),
            'contact_email' => $this->nullable($row['Facility_Contact_Email'] ?? null),
            'contact_phone' => $this->nullable($row['Facility_Contact_Phone'] ?? null),
        ];

        $facility = FdaWddFacility::query()->firstOrNew([
            'fda_organization_id' => $org->id,
            'facility_type' => $facilityType->value,
            'address_fingerprint' => $fingerprint,
        ]);

        $wasNew = ! $facility->exists;
        $facility->fillFromFda($attributes);

        if ($wasNew) {
            $counts['inserted']++;
        } else {
            $counts['updated']++;
        }

        return $facility;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function upsertLicense(array $row, FdaWddFacility $facility): ?FdaWddLicense
    {
        $number = $this->nullable($row['License_Number'] ?? null);
        $jurisdiction = $this->normalizeState($row['License_State'] ?? null);

        if ($number === null || $jurisdiction === null) {
            return null;
        }

        $year = trim((string) ($row['Reporting_Year'] ?? ''));

        $license = FdaWddLicense::query()->firstOrNew([
            'fda_wdd_facility_id' => $facility->id,
            'jurisdiction' => $jurisdiction,
            'license_number' => $number,
        ]);

        $license->fillFromFda([
            'expiration_date' => FdaDate::toDateString($row['License_Expiration_Date'] ?? null),
            'reporting_year' => ctype_digit($year) ? (int) $year : null,
            'is_active' => true,
        ]);

        return $license;
    }

    private function normalizeState(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'US-')) {
            $value = substr($value, 3);
        }

        return UsState::normalize($value) ?? strtoupper(substr($value, 0, 2));
    }

    private function fileHash(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $hash = hash_file('sha256', $path);

        return $hash === false ? null : $hash;
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = str_replace("\xAD", '', $value);
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        $value = str_replace("\u{00AD}", '', $value);

        return $value === '' ? null : $value;
    }
}
