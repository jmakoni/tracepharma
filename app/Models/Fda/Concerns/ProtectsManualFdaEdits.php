<?php

namespace App\Models\Fda\Concerns;

/**
 * Periodic FDA imports refresh unedited attributes and leave admin edits alone.
 */
trait ProtectsManualFdaEdits
{
    protected bool $fillingFromFda = false;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function fillFromFda(array $attributes): static
    {
        $this->fillingFromFda = true;

        try {
            $this->fill(array_diff_key($attributes, array_flip($this->manuallyEditedFields())));
            $this->save();
        } finally {
            $this->fillingFromFda = false;
        }

        return $this;
    }

    public function isFdaFieldFrozen(string $field): bool
    {
        return in_array($field, $this->manuallyEditedFields(), true);
    }

    /**
     * @return list<string>
     */
    public function manuallyEditedFields(): array
    {
        if (! $this->tracksManualFdaEdits()) {
            return [];
        }

        $fields = $this->getAttribute('manually_edited_fields') ?? [];

        if (! is_array($fields)) {
            return [];
        }

        return array_values(array_filter(
            $fields,
            static fn (mixed $field): bool => is_string($field) && $field !== '',
        ));
    }

    public function tracksManualFdaEdits(): bool
    {
        return array_key_exists('manually_edited_fields', $this->getCasts());
    }

    public static function bootProtectsManualFdaEdits(): void
    {
        static::saving(function (self $model): void {
            if ($model->fillingFromFda || ! $model->tracksManualFdaEdits() || ! $model->exists) {
                return;
            }

            $dirty = array_values(array_intersect(
                array_keys($model->getDirty()),
                $model->getFillable(),
            ));

            if ($dirty === []) {
                return;
            }

            $model->setAttribute(
                'manually_edited_fields',
                array_values(array_unique([...$model->manuallyEditedFields(), ...$dirty])),
            );
        });
    }
}
