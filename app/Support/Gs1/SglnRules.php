<?php

namespace App\Support\Gs1;

use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/**
 * One place to configure every SGLN input in the app.
 *
 * An SGLN is only accepted in its GS1 Pure Identity form — three segments, and the
 * company prefix plus location reference encoding the GLN on the same record. That
 * pairing is the whole point of storing it: it is where the company-prefix split
 * comes from when we author the location on a DSCSA transaction.
 */
final class SglnRules
{
    public static function input(string $name = 'sgln', string $label = 'SGLN'): TextInput
    {
        return self::apply(TextInput::make($name)->label($label));
    }

    public static function apply(TextInput $input, string $glnField = 'gln'): TextInput
    {
        return $input
            ->maxLength(64)
            ->placeholder('urn:epc:id:sgln:0614141.12345.0')
            ->rule(fn (Get $get): Closure => static function (
                string $attribute,
                mixed $value,
                Closure $fail,
            ) use ($get, $glnField): void {
                $message = self::check(
                    is_string($value) ? $value : null,
                    is_string($get($glnField)) ? $get($glnField) : null,
                );

                if ($message !== null) {
                    $fail($message);
                }
            })
            ->extraInputAttributes(['autocomplete' => 'off']);
    }

    /**
     * The reason this SGLN cannot be stored against this GLN, or null when it can.
     * Blank passes; the SGLN is optional everywhere it is offered.
     */
    public static function check(?string $sgln, ?string $gln): ?string
    {
        if ($sgln === null || trim($sgln) === '') {
            return null;
        }

        $parsed = Sgln::fromUrn($sgln);

        if ($parsed === null) {
            return 'The SGLN must be a GS1 Pure Identity URN: urn:epc:id:sgln:companyPrefix.locationReference.extension';
        }

        $normalizedGln = Sgln::normalizeGln($gln);

        if ($normalizedGln !== null && $parsed['gln'] !== $normalizedGln) {
            return 'The SGLN encodes GLN '.$parsed['gln'].', not '.$normalizedGln.'.';
        }

        return null;
    }
}
