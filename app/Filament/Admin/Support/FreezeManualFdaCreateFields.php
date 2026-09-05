<?php

namespace App\Filament\Admin\Support;

use App\Models\Fda\FdaModel;
use Illuminate\Database\Eloquent\Model;

/**
 * Mark admin-entered identity fields as manually edited after Filament create,
 * without changing ProtectsManualFdaEdits (which correctly skips on first insert
 * so FDA import creates are not frozen).
 */
final class FreezeManualFdaCreateFields
{
    /** @var list<string> */
    private const IDENTITY_FIELDS = [
        'dea_number',
        'hin_number',
        'chemical_reg_number',
        'duns_number',
        'gln',
        'sgln',
        'name',
        'firm_name',
        'facility_name',
    ];

    public static function afterCreate(Model $record): void
    {
        if (! $record instanceof FdaModel || ! $record->tracksManualFdaEdits()) {
            return;
        }

        $filled = [];
        foreach (self::IDENTITY_FIELDS as $field) {
            if (! in_array($field, $record->getFillable(), true)) {
                continue;
            }

            if (filled($record->getAttribute($field))) {
                $filled[] = $field;
            }
        }

        if ($filled === []) {
            return;
        }

        $record->forceFill([
            'manually_edited_fields' => array_values(array_unique([
                ...$record->manuallyEditedFields(),
                ...$filled,
            ])),
        ])->saveQuietly();
    }
}
