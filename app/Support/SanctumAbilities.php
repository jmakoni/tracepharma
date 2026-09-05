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

    public const EPCIS_SUBSCRIPTIONS = 'epcis:subscriptions';

    public const VRS_DISPENSE_CHECK = 'vrs:dispense-check';

    public const WMS_SHIP_CONFIRM = 'wms:ship-confirm';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::EPCIS_UPLOAD => 'Upload / GS1 Capture inbound EPCIS (XML 1.2/1.3 or JSON-LD 2.0 when enabled)',
            self::EPCIS_VIEW => 'List, query-as-2.0, and GS1 SimpleEventQuery for inbound EPCIS',
            self::EPCIS_TRANSMIT => 'Transmit outbound EPCIS',
            self::EPCIS_SUBSCRIPTIONS => 'Manage GS1-shaped EPCIS subscriptions (subscribe/unsubscribe)',
            self::VRS_DISPENSE_CHECK => 'Dispense-check (VRS verification gate)',
            self::WMS_SHIP_CONFIRM => 'WMS ship-confirm (Connector)',
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
