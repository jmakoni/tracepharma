<?php

namespace Tracepharma\FilamentUiExtras\Macros;

use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Illuminate\Support\Str;

final class InlineLabelPrefixMacros
{
    protected static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        foreach ([Select::class, TextInput::class, DateTimePicker::class, DatePicker::class, TimePicker::class] as $component) {
            $component::macro('inlineLabelPrefix', function (bool|Closure $condition = true) {
                /** @var Field $this */
                $enabled = $condition instanceof Closure ? (bool) $condition($this) : (bool) $condition;

                if (! $enabled) {
                    return $this;
                }

                $label = $this->getLabel();

                if (blank($label)) {
                    $label = (string) Str::of($this->getName())
                        ->afterLast('.')
                        ->headline();
                }

                $classes = ['fi-uie-inline-label-prefix'];

                if ($this instanceof Select) {
                    $classes[] = 'fi-uie-inline-label-prefix--mute-value';
                }

                return $this
                    ->hiddenLabel()
                    ->extraFieldWrapperAttributes([
                        'class' => implode(' ', $classes),
                        'data-uie-label' => is_string($label) ? $label : '',
                    ], merge: true);
            });
        }
    }
}
