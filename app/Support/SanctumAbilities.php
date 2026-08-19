<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\ValidationException;

class SanctumAbilities
{
    public const ALL = '*';

    public const EPCIS_UPLOAD = 'epcis:upload';

    public const EPCIS_VIEW = 'epcis:view';

    public const EPCIS_TRANSMIT = 'epcis:transmit';

    public const VRS_DISPENSE_CHECK = 'vrs:dispense-check';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::EPCIS_UPLOAD => 'Upload inbound EPCIS XML',
            self::EPCIS_VIEW => 'List inbound EPCIS documents',
            self::EPCIS_TRANSMIT => 'Transmit outbound EPCIS XML',
            self::VRS_DISPENSE_CHECK => 'Dispense-check (VRS verification gate)',
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedKeys(): array
    {
        return array_keys(self::options());
    }

    /**
     * @param  list<mixed>  $abilities
     * @return list<string>
     */
    public static function validateForTokenCreation(array $abilities): array
    {
        $normalized = array_values(array_unique(array_filter(
            $abilities,
            static fn (mixed $ability): bool => is_string($ability) && $ability !== '',
        )));

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'abilities' => 'Select at least one ability.',
            ]);
        }

        if (in_array(self::ALL, $normalized, true)) {
            throw ValidationException::withMessages([
                'abilities' => 'The * ability is not allowed.',
            ]);
        }

        $invalid = array_diff($normalized, self::allowedKeys());
        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'abilities' => 'One or more selected abilities are not allowed.',
            ]);
        }

        return $normalized;
    }
}
