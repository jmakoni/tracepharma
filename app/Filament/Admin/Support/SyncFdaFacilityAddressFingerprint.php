<?php

namespace App\Filament\Admin\Support;

use App\Models\Fda\FdaWddFacility;
use App\Support\Fda\AddressFingerprint;
use Closure;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

/**
 * Keep FDA facility address fingerprints in sync when admins create/edit rows.
 */
final class SyncFdaFacilityAddressFingerprint
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function apply(array $data): array
    {
        if (blank($data['country_code'] ?? null)) {
            $data['country_code'] = 'US';
        }

        if (blank($data['name'] ?? null)) {
            $firm = $data['firm_name'] ?? null;
            $facility = $data['facility_name'] ?? null;
            $data['name'] = filled($firm) ? $firm : (filled($facility) ? $facility : null);
        }

        $data['address_fingerprint'] = AddressFingerprint::make(
            is_string($data['street_address'] ?? null) ? $data['street_address'] : null,
            is_string($data['city'] ?? null) ? $data['city'] : null,
            is_string($data['state_province'] ?? null) ? $data['state_province'] : null,
            is_string($data['postal_code'] ?? null) ? $data['postal_code'] : null,
            is_string($data['country_code'] ?? null) ? $data['country_code'] : null,
            is_string($data['full_address'] ?? null) ? $data['full_address'] : null,
        );

        return $data;
    }

    /**
     * Form rule: WDD unique (org, facility_type, address_fingerprint).
     *
     * @return Closure(Get): Closure
     */
    public static function uniqueWddAddressRule(?Model $record = null): Closure
    {
        return function (Get $get) use ($record): Closure {
            return function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                $organizationId = $get('fda_organization_id');
                $facilityType = $get('facility_type');

                if (blank($organizationId) || blank($facilityType)) {
                    return;
                }

                $fingerprint = AddressFingerprint::make(
                    is_string($get('street_address')) ? $get('street_address') : null,
                    is_string($get('city')) ? $get('city') : null,
                    is_string($get('state_province')) ? $get('state_province') : null,
                    is_string($get('postal_code')) ? $get('postal_code') : null,
                    filled($get('country_code')) ? (string) $get('country_code') : 'US',
                    is_string($get('full_address')) ? $get('full_address') : null,
                );

                $query = FdaWddFacility::query()
                    ->where('fda_organization_id', (int) $organizationId)
                    ->where('facility_type', $facilityType)
                    ->where('address_fingerprint', $fingerprint);

                if ($record instanceof FdaWddFacility && $record->exists) {
                    $query->whereKeyNot($record->getKey());
                }

                if ($query->exists()) {
                    $fail('A WDD facility with this organization, type, and address already exists.');
                }
            };
        };
    }
}
