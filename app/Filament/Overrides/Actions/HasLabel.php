<?php

namespace Filament\Actions\Concerns;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

/**
 * TracePharma override of Filament's HasLabel: button labels use Str::ucwords().
 *
 * Loaded via composer autoload "files" + classmap exclusion of the vendor trait.
 *
 * @see vendor/filament/actions/src/Concerns/HasLabel.php
 */
trait HasLabel
{
    protected string|Htmlable|Closure|null $label = null;

    protected bool|Closure $isLabelHidden = false;

    protected bool $shouldTranslateLabel = false;

    /**
     * @deprecated Use `hiddenLabel()` instead.
     */
    public function disableLabel(bool|Closure $condition = true): static
    {
        $this->hiddenLabel($condition);

        return $this;
    }

    public function hiddenLabel(bool|Closure $condition = true): static
    {
        $this->isLabelHidden = $condition;

        return $this;
    }

    public function label(string|Htmlable|Closure|null $label): static
    {
        if (is_string($label)) {
            $label = Str::ucwords($label);
        } elseif ($label instanceof Closure) {
            $original = $label;
            $label = function () use ($original): string|Htmlable|null {
                $resolved = $this->evaluate($original);

                return is_string($resolved) ? Str::ucwords($resolved) : $resolved;
            };
        }

        $this->label = $label;

        return $this;
    }

    public function translateLabel(bool $shouldTranslateLabel = true): static
    {
        $this->shouldTranslateLabel = $shouldTranslateLabel;

        return $this;
    }

    public function getLabel(): string|Htmlable|null
    {
        $label = $this->evaluate($this->label) ?? (string) str($this->getName())
            ->before('.')
            ->kebab()
            ->replace(['-', '_'], ' ')
            ->ucfirst();

        if (is_string($label) && $this->shouldTranslateLabel) {
            $label = __($label);
        }

        return is_string($label) ? Str::ucwords($label) : $label;
    }

    public function isLabelHidden(): bool
    {
        return (bool) $this->evaluate($this->isLabelHidden);
    }

    public function hasLabelHidden(): bool
    {
        return $this->isLabelHidden !== false;
    }
}
