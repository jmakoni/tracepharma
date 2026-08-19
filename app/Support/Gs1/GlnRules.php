<?php

namespace App\Support\Gs1;

use App\Rules\ValidGln;
use Filament\Forms\Components\TextInput;

/**
 * One place to configure every GLN input in the app: 13 digits, GS1 check digit,
 * numeric keypad on the warehouse floor.
 *
 * Callers chain the field-specific bits (required, unique, column span) on top.
 */
final class GlnRules
{
    public static function input(string $name = 'gln', string $label = 'GLN'): TextInput
    {
        return self::apply(TextInput::make($name)->label($label));
    }

    public static function apply(TextInput $input): TextInput
    {
        return $input
            ->length(13)
            ->rule(new ValidGln)
            ->placeholder('0614141000005')
            ->extraInputAttributes(['inputmode' => 'numeric', 'autocomplete' => 'off']);
    }
}
