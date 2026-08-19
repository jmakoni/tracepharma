<?php

namespace App\Actions\Fda;

use App\Enums\FacilityType;
use App\Models\Fda\FdaWdd3plUnmatched;
use App\Support\PartnerSlug;
use Illuminate\Support\Carbon;

/**
 * Persist unmatched WDD/3PL facility names for admin triage after import.
 */
final class UpsertFdaWdd3plUnmatched
{
    /**
     * @param  array<string, int>  $unmatchedFacilities
     * @param  array<string, string>  $facilityTypes  FDA Type per facility name, when the report agrees on one
     * @return array{upserted: int}
     */
    public function handle(array $unmatchedFacilities, array $facilityTypes = []): array
    {
        if ($unmatchedFacilities === []) {
            return ['upserted' => 0];
        }

        $now = Carbon::now();
        $upserted = 0;

        foreach ($unmatchedFacilities as $facilityName => $rowCount) {
            $facilityName = trim((string) $facilityName);

            if ($facilityName === '') {
                continue;
            }

            $rowCount = max(0, (int) $rowCount);
            $slugAttempt = PartnerSlug::from($facilityName);
            $facilityType = FacilityType::tryFrom((string) ($facilityTypes[$facilityName] ?? ''))?->value;

            $existing = FdaWdd3plUnmatched::query()
                ->where('facility_name', $facilityName)
                ->first();

            if ($existing !== null) {
                $existing->update([
                    'slug_attempt' => $slugAttempt,
                    'facility_type' => $facilityType ?? $existing->facility_type,
                    'row_count' => $rowCount,
                    'last_seen_at' => $now,
                ]);
            } else {
                FdaWdd3plUnmatched::query()->create([
                    'facility_name' => $facilityName,
                    'slug_attempt' => $slugAttempt,
                    'facility_type' => $facilityType,
                    'row_count' => $rowCount,
                    'last_seen_at' => $now,
                ]);
            }

            $upserted++;
        }

        return ['upserted' => $upserted];
    }
}
