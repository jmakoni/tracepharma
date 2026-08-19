<?php

namespace App\Actions\Catalog;

use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWddFacility;
use App\Support\Catalog\DisplayName;
use App\Support\Catalog\Gln;
use App\Support\Fda\AddressFingerprint;
use App\Support\Geo\TimezoneFromAddress;
use App\Support\Places\UsState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Blank-fills existing FDA rows from a McKesson Sold-To / Ship-To TSV.
 *
 * Sold-To GLN must already exist on {@see FdaOrganization}. Each Ship-To GLN
 * must already exist on {@see FdaWddFacility} or {@see FdaEstablishment}.
 * Unmatched GLNs are skipped — this path never inserts pharmacies or catalog rows.
 */
final class ImportMckessonSoldShipTo
{
    /**
     * @return array{
     *     partner_created: int,
     *     partner_updated: int,
     *     hq_updated: int,
     *     sites_upserted: int,
     *     skipped: int,
     *     gln_conflicts: int,
     *     dry_run: bool,
     *     partner_id: ?int,
     *     near_duplicates: list<array{id: int, name: string, slug: string, gln: ?string, city: ?string}>
     * }
     */
    public function handle(string $path, bool $dryRun = false): array
    {
        $summary = [
            'partner_created' => 0,
            'partner_updated' => 0,
            'hq_updated' => 0,
            'sites_upserted' => 0,
            'skipped' => 0,
            'gln_conflicts' => 0,
            'dry_run' => $dryRun,
            'partner_id' => null,
            'near_duplicates' => [],
        ];

        [$soldTo, $shipTos] = $this->parseFile($path);

        if ($soldTo === null) {
            throw new RuntimeException('McKesson TSV has no Sold To row.');
        }

        $corporateGln = Gln::normalize($soldTo['Ship To GLN'] ?? null);

        if ($corporateGln === null) {
            throw new RuntimeException('Sold To row is missing a usable Ship To GLN.');
        }

        $organization = FdaOrganization::query()->where('gln', $corporateGln)->first();

        if ($organization === null) {
            $summary['skipped']++;
        } else {
            $summary['partner_updated'] = 1;
            $summary['hq_updated'] = 1;
            $summary['partner_id'] = $organization->id;
        }

        $siteWork = [];

        foreach ($shipTos as $row) {
            $gln = Gln::normalize($row['Ship To GLN'] ?? null);

            if ($gln === null) {
                $summary['skipped']++;

                continue;
            }

            $matches = $this->sitesForGln($gln);

            if ($matches === []) {
                $summary['skipped']++;

                continue;
            }

            $summary['sites_upserted']++;
            $siteWork[] = ['row' => $row, 'matches' => $matches];
        }

        $summary['near_duplicates'] = $this->nearDuplicates($organization?->id);

        if ($dryRun) {
            return $summary;
        }

        return DB::transaction(function () use ($summary, $organization, $soldTo, $siteWork): array {
            if ($organization !== null) {
                $this->fillBlanks($organization, $this->organizationAttributes($soldTo));
                $organization->save();
            }

            foreach ($siteWork as $item) {
                $attributes = $this->siteAttributes($item['row']);

                foreach ($item['matches'] as $site) {
                    $this->fillBlanks($site, $this->attributesForSite($site, $attributes));
                    $site->save();
                }
            }

            return $summary;
        });
    }

    /**
     * @return array{0: ?array<string, string>, 1: list<array<string, string>>}
     */
    private function parseFile(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("McKesson TSV is not readable: {$path}");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Unable to open McKesson TSV: {$path}");
        }

        try {
            $header = fgetcsv($handle, 0, "\t");

            if ($header === false || $header === [null] || $header === []) {
                throw new RuntimeException('McKesson TSV is missing a header row.');
            }

            $header = array_map(static fn ($column) => trim((string) $column), $header);
            $soldTo = null;
            $shipTos = [];

            while (($data = fgetcsv($handle, 0, "\t")) !== false) {
                if ($data === [null] || $data === []) {
                    continue;
                }

                $row = [];
                foreach ($header as $index => $column) {
                    $row[$column] = trim((string) ($data[$index] ?? ''));
                }

                $type = strtolower($row['Type'] ?? '');

                if ($type === 'sold to') {
                    $soldTo = $row;
                } elseif ($type === 'ship to') {
                    $shipTos[] = $row;
                }
            }

            return [$soldTo, $shipTos];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return list<FdaWddFacility|FdaEstablishment>
     */
    private function sitesForGln(string $gln): array
    {
        return [
            ...FdaWddFacility::query()->where('gln', $gln)->get()->all(),
            ...FdaEstablishment::query()->where('gln', $gln)->get()->all(),
        ];
    }

    /**
     * @param  array<string, string>  $soldTo
     * @return array<string, mixed>
     */
    private function organizationAttributes(array $soldTo): array
    {
        $notes = trim(implode(' ', array_filter([
            $soldTo['Additional Notes #1'] ?? '',
            $soldTo['Additional Notes #2'] ?? '',
        ])));

        $state = UsState::normalize($soldTo['#state'] ?? null)
            ?? ($soldTo['#state'] !== '' ? strtoupper($soldTo['#state']) : null);
        $country = $soldTo['#countryCode'] !== '' ? strtoupper($soldTo['#countryCode']) : 'US';
        $city = $soldTo['#city'] !== '' ? $soldTo['#city'] : null;

        return [
            'name' => 'McKesson',
            'canonical_name' => 'MCKESSON',
            'doing_business_as' => $soldTo['Customer Name'] !== '' ? $soldTo['Customer Name'] : 'McKesson Corporate',
            'description' => $notes !== '' ? $notes : null,
            'street_address' => $soldTo['#streetAddressOne'] !== '' ? $soldTo['#streetAddressOne'] : null,
            'street_address_2' => $soldTo['#streetAddressTwo'] !== '' ? $soldTo['#streetAddressTwo'] : null,
            'city' => $city,
            'state_province' => $state,
            'postal_code' => Gln::normalizePostalCode($soldTo['#postalCode'] ?? null),
            'country_code' => $country,
            'timezone' => TimezoneFromAddress::resolve($country, $state, $city),
            'email' => $soldTo['Contact #1 Email'] !== '' ? $soldTo['Contact #1 Email'] : null,
            'is_active' => true,
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function siteAttributes(array $row): array
    {
        $state = UsState::normalize($row['#state'] ?? null)
            ?? ($row['#state'] !== '' ? strtoupper($row['#state']) : null);
        $country = $row['#countryCode'] !== '' ? strtoupper($row['#countryCode']) : 'US';
        $city = $row['#city'] !== '' ? $row['#city'] : null;
        $street = $row['#streetAddressOne'] !== '' ? $row['#streetAddressOne'] : null;
        $postal = Gln::normalizePostalCode($row['#postalCode'] ?? null);
        $name = DisplayName::clean(
            $row['#name'] !== '' ? $row['#name'] : ($row['Customer Name'] ?? 'McKesson Site')
        ) ?? 'McKesson Site';

        return [
            'name' => $name,
            'street_address' => $street,
            'street_address_2' => $row['#streetAddressTwo'] !== '' ? $row['#streetAddressTwo'] : null,
            'city' => $city,
            'state_province' => $state,
            'postal_code' => $postal,
            'country_code' => $country,
            'timezone' => TimezoneFromAddress::resolve($country, $state, $city),
            'email' => $row['Contact #1 Email'] !== '' ? $row['Contact #1 Email'] : null,
            'address_fingerprint' => AddressFingerprint::make($street, $city, $state, $postal, $country),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function attributesForSite(Model $site, array $attributes): array
    {
        $mapped = $attributes;

        if ($site instanceof FdaWddFacility) {
            $mapped['facility_name'] = $attributes['name'];
            $mapped['contact_email'] = $attributes['email'];
            unset($mapped['email']);
        }

        if ($site instanceof FdaEstablishment) {
            $mapped['firm_name'] = $attributes['name'];
            $mapped['establishment_contact_email'] = $attributes['email'];
            unset($mapped['email']);
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function fillBlanks(Model $model, array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $current = $model->getAttribute($key);

            if ($current !== null && $current !== '') {
                continue;
            }

            $model->setAttribute($key, $value);
        }
    }

    /**
     * @return list<array{id: int, name: string, slug: string, gln: ?string, city: ?string}>
     */
    private function nearDuplicates(?int $canonicalOrganizationId): array
    {
        return FdaOrganization::query()
            ->where(function ($query): void {
                $query->where('name', 'like', '%McKesson%')
                    ->orWhere('name', 'like', '%Mckesson%')
                    ->orWhere('canonical_name', 'like', '%MCKESSON%');
            })
            ->when($canonicalOrganizationId !== null, fn ($query) => $query->where('id', '!=', $canonicalOrganizationId))
            ->orderBy('id')
            ->get(['id', 'name', 'canonical_name', 'gln', 'city'])
            ->map(fn (FdaOrganization $organization): array => [
                'id' => $organization->id,
                'name' => (string) $organization->name,
                'slug' => (string) ($organization->canonical_name ?? ''),
                'gln' => $organization->gln,
                'city' => $organization->city,
            ])
            ->all();
    }
}
