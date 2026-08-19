<?php

namespace App\Support\Fda;

use App\Models\Fda\FdaOrganization;
use App\Support\PartnerSlug;

final class FdaOrganizationSlugIndex
{
    /**
     * @return array<string, int>
     */
    public static function map(): array
    {
        $map = [];

        FdaOrganization::query()
            ->orderBy('id')
            ->get(['id', 'canonical_name', 'name', 'original_name'])
            ->each(function (FdaOrganization $organization) use (&$map): void {
                foreach ([$organization->canonical_name, $organization->name, $organization->original_name] as $name) {
                    if (! filled($name)) {
                        continue;
                    }

                    $slug = PartnerSlug::from((string) $name);
                    $map[$slug] ??= (int) $organization->id;
                }
            });

        return $map;
    }
}
