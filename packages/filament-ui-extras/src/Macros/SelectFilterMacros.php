<?php

namespace Tracepharma\FilamentUiExtras\Macros;

use Closure;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;

final class SelectFilterMacros
{
    protected static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        SelectFilter::macro('hiddenLabel', function (bool|Closure $condition = true): SelectFilter {
            /** @var SelectFilter $this */
            return $this->modifyFormFieldUsing(function (Select $field) use ($condition): Select {
                return $field
                    ->hiddenLabel($condition)
                    ->extraFieldWrapperAttributes([
                        'class' => 'fi-uie-select-filter-hidden-label',
                    ], merge: true);
            });
        });

        SelectFilter::macro('inlineLabel', function (bool|Closure $condition = true): SelectFilter {
            /** @var SelectFilter $this */
            $filter = $this;

            return $this->modifyFormFieldUsing(function (Select $field) use ($condition, $filter): Select {
                $enabled = $condition instanceof Closure ? (bool) $condition() : (bool) $condition;

                if (! $enabled) {
                    return $field;
                }

                $label = $filter->getLabel();

                return $field
                    ->hiddenLabel()
                    ->extraFieldWrapperAttributes([
                        'class' => 'fi-uie-inline-label-prefix fi-uie-select-filter-inline-label',
                        'data-uie-label' => is_string($label) ? $label : '',
                    ], merge: true);
            });
        });
    }
}
