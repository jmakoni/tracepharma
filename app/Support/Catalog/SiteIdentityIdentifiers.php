<?php

namespace App\Support\Catalog;

use App\Models\Site;

/**
 * Identity numbers shown on the site profile header (view and slide-over).
 */
final class SiteIdentityIdentifiers
{
    /**
     * GLN is always first (blank becomes a dash in the view). Other identifiers
     * appear only when they have a value so the compact ATP tab stays dense.
     *
     * @return list<array{label: string, value: ?string}>
     */
    public static function rows(Site $site): array
    {
        $site->loadMissing('fdaEstablishment');

        $rows = [
            ['label' => 'GLN', 'value' => self::trim($site->gln)],
        ];

        foreach ([
            ['SGLN', $site->sgln],
            ['FEI', $site->fdaEstablishment?->fei_number],
            ['DEA', $site->dea_number],
            ['DUNS', $site->duns_number],
            ['HIN', $site->hin_number],
            ['Chemical Reg', $site->chemical_reg_number],
        ] as [$label, $value]) {
            $trimmed = self::trim($value);

            if ($trimmed === null) {
                continue;
            }

            $rows[] = ['label' => $label, 'value' => $trimmed];
        }

        return $rows;
    }

    private static function trim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
