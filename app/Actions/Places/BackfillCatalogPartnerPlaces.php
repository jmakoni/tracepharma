<?php

namespace App\Actions\Places;

use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWddFacility;
use App\Support\Catalog\DisplayName;
use App\Support\Catalog\Gln;
use App\Support\Fda\AddressFingerprint;
use App\Support\Places\PlacesClient;
use App\Support\Places\PlacesResultSelector;
use App\Support\Places\PlacesSearchQueryBuilder;
use App\Support\Places\UsState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Backfills an FDA organization's blank address/geo fields from a Places search.
 *
 * Existing establishments and WDD facilities under that org are blank-filled
 * when a place GLN or address fingerprint matches. New FDA sites are never created.
 */
final class BackfillCatalogPartnerPlaces
{
    /**
     * Organization attributes eligible to be filled from the selected HQ place.
     *
     * @var list<string>
     */
    private const ORGANIZATION_FIELDS = [
        'street_address',
        'city',
        'state_province',
        'postal_code',
        'country_code',
        'timezone',
        'latitude',
        'longitude',
        'telephone',
        'website',
    ];

    public function __construct(
        private readonly PlacesClient $client,
        private readonly PlacesResultSelector $selector,
        private readonly PlacesSearchQueryBuilder $queryBuilder,
    ) {}

    /**
     * @return array{skipped_has_address: int, no_results: int, hq_filled: int, sites_upserted: int, rejected: int, queries_tried: int, dry_run: bool}
     */
    public function handle(FdaOrganization $organization, bool $onlyMissing = true, bool $dryRun = false): array
    {
        $result = [
            'skipped_has_address' => 0,
            'no_results' => 0,
            'hq_filled' => 0,
            'sites_upserted' => 0,
            'rejected' => 0,
            'queries_tried' => 0,
            'dry_run' => $dryRun,
        ];

        if ($onlyMissing && filled($organization->street_address)) {
            $result['skipped_has_address'] = 1;

            return $result;
        }

        $selection = ['hq' => null, 'sites' => [], 'rejected' => 0];

        foreach ($this->queryBuilder->queries($organization->name) as $query) {
            $result['queries_tried']++;
            $places = $this->client->search($query);
            $selection = $this->selector->select((string) $organization->name, $places);
            $result['rejected'] += $selection['rejected'];

            if ($selection['hq'] !== null) {
                break;
            }
        }

        if ($selection['hq'] === null) {
            $result['no_results'] = 1;

            return $result;
        }

        $hqAttributes = $this->mapPlaceToAttributes($selection['hq']);
        $matchedSites = $this->matchExistingSites($organization, [$selection['hq'], ...$selection['sites']]);

        $result['hq_filled'] = 1;
        $result['sites_upserted'] = count($matchedSites);

        if ($dryRun) {
            return $result;
        }

        DB::transaction(function () use ($organization, $hqAttributes, $onlyMissing, $matchedSites): void {
            $this->fillOrganizationFields($organization, $hqAttributes, $onlyMissing);
            $organization->save();

            foreach ($matchedSites as $site) {
                $this->fillSiteBlanks($site['model'], $site['attributes']);
                $site['model']->save();
            }
        });

        return $result;
    }

    /**
     * @param  array<string, mixed>  $hqAttributes
     */
    private function fillOrganizationFields(FdaOrganization $organization, array $hqAttributes, bool $onlyMissing): void
    {
        foreach (self::ORGANIZATION_FIELDS as $field) {
            $value = $hqAttributes[$field] ?? null;

            if ($value === null) {
                continue;
            }

            if ($onlyMissing && filled($organization->{$field})) {
                continue;
            }

            $organization->{$field} = $value;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $places
     * @return list<array{model: FdaWddFacility|FdaEstablishment, attributes: array<string, mixed>}>
     */
    private function matchExistingSites(FdaOrganization $organization, array $places): array
    {
        $matched = [];
        $seen = [];

        foreach ($places as $place) {
            if (! is_array($place)) {
                continue;
            }

            $attributes = $this->mapPlaceToAttributes($place);
            $gln = Gln::normalize(isset($place['gln']) ? (string) $place['gln'] : null);
            $fingerprint = AddressFingerprint::make(
                $attributes['street_address'],
                $attributes['city'],
                $attributes['state_province'],
                $attributes['postal_code'],
                $attributes['country_code'],
            );

            $sites = [
                ...$this->sitesFor($organization, FdaWddFacility::class, $gln, $fingerprint),
                ...$this->sitesFor($organization, FdaEstablishment::class, $gln, $fingerprint),
            ];

            foreach ($sites as $site) {
                $key = $site::class.'-'.$site->getKey();

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $matched[] = ['model' => $site, 'attributes' => $attributes];
            }
        }

        return $matched;
    }

    /**
     * @param  class-string<FdaWddFacility|FdaEstablishment>  $model
     * @return list<FdaWddFacility|FdaEstablishment>
     */
    private function sitesFor(FdaOrganization $organization, string $model, ?string $gln, string $fingerprint): array
    {
        return $model::query()
            ->where('fda_organization_id', $organization->id)
            ->where(function ($query) use ($gln, $fingerprint): void {
                $query->where('address_fingerprint', $fingerprint);

                if ($gln !== null) {
                    $query->orWhere('gln', $gln);
                }
            })
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function fillSiteBlanks(Model $site, array $attributes): void
    {
        $mapped = [
            'name' => $attributes['name'],
            'street_address' => $attributes['street_address'],
            'city' => $attributes['city'],
            'state_province' => $attributes['state_province'],
            'postal_code' => $attributes['postal_code'],
            'country_code' => $attributes['country_code'],
            'timezone' => $attributes['timezone'],
            'latitude' => $attributes['latitude'],
            'longitude' => $attributes['longitude'],
            'address_fingerprint' => AddressFingerprint::make(
                $attributes['street_address'],
                $attributes['city'],
                $attributes['state_province'],
                $attributes['postal_code'],
                $attributes['country_code'],
            ),
        ];

        if ($site instanceof FdaWddFacility) {
            $mapped['facility_name'] = $attributes['name'];
            $mapped['contact_email'] = null;
        }

        if ($site instanceof FdaEstablishment) {
            $mapped['firm_name'] = $attributes['name'];
        }

        foreach ($mapped as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $current = $site->getAttribute($key);

            if ($current !== null && $current !== '') {
                continue;
            }

            $site->setAttribute($key, $value);
        }
    }

    /**
     * @param  array<string, mixed>  $place
     * @return array<string, mixed>
     */
    private function mapPlaceToAttributes(array $place): array
    {
        $state = $place['state'] ?? null;

        return [
            'name' => DisplayName::clean(isset($place['name']) ? (string) $place['name'] : null),
            'street_address' => $place['street_address'] ?? $place['address'] ?? null,
            'city' => $place['city'] ?? null,
            'state_province' => UsState::normalize($state) ?? $state,
            'postal_code' => $place['zipcode'] ?? $place['postal_code'] ?? null,
            'country_code' => $this->normalizeCountry($place['country'] ?? $place['country_code'] ?? null),
            'timezone' => $place['timezone'] ?? null,
            'latitude' => $place['latitude'] ?? null,
            'longitude' => $place['longitude'] ?? null,
            'telephone' => $place['phone_number'] ?? $place['telephone'] ?? null,
            'website' => $place['website'] ?? null,
        ];
    }

    private function normalizeCountry(?string $country): ?string
    {
        if (blank($country)) {
            return null;
        }

        $trimmed = trim($country);

        if (in_array(strtolower($trimmed), ['united states', 'usa', 'us'], true)) {
            return 'US';
        }

        return strtoupper(substr($trimmed, 0, 3));
    }
}
