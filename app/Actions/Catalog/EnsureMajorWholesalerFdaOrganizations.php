<?php

namespace App\Actions\Catalog;

use App\Enums\PartnerType;
use App\Models\Fda\FdaOrganization;
use App\Support\MasterData\MajorWholesalers;

/**
 * Ensure Top 6 major wholesaler rows exist as FDA organizations (by GLN).
 */
final class EnsureMajorWholesalerFdaOrganizations
{
    public function handle(): int
    {
        $ensured = 0;

        foreach (MajorWholesalers::definitions() as $definition) {
            $canonical = strtoupper($definition['name']);

            $organization = FdaOrganization::query()->where('gln', $definition['gln'])->first()
                ?? FdaOrganization::query()->where('canonical_name', $canonical)->first();

            if ($organization === null) {
                FdaOrganization::query()->create([
                    'gln' => $definition['gln'],
                    'original_name' => $definition['name'],
                    'canonical_name' => $canonical,
                    'name' => $definition['name'],
                    'partner_type' => PartnerType::Wholesaler,
                    'is_active' => true,
                ]);
                $ensured++;

                continue;
            }

            $updates = [];

            if (blank($organization->gln)) {
                $updates['gln'] = $definition['gln'];
            }

            if ($organization->name === null || $organization->name === '') {
                $updates['name'] = $definition['name'];
            }

            if ($organization->canonical_name === null || $organization->canonical_name === '') {
                $updates['canonical_name'] = $canonical;
            }

            if ($organization->partner_type === null) {
                $updates['partner_type'] = PartnerType::Wholesaler;
            }

            if ($organization->is_active === null) {
                $updates['is_active'] = true;
            }

            if ($updates !== []) {
                $organization->update($updates);
            }

            $ensured++;
        }

        return $ensured;
    }
}
