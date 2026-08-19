<?php

namespace App\Support\Catalog;

/**
 * Combine an FDA product's per-ingredient strengths into one catalog `strength`.
 *
 * openFDA publishes a strength per active ingredient, so a combination product
 * (amlodipine / valsartan) carries several. Storing only the first understated
 * the package — "5 mg/1" for a 5 mg / 160 mg tablet — and made two different
 * combinations look identical on a receiving screen.
 *
 * Ingredient strengths already contain "/" (openFDA spells them "5 mg/1"), so
 * they are joined with "; ". A single-ingredient product keeps exactly the value
 * it had before, which is what the rest of the app and the EPCIS
 * strengthDescription attribute already carry.
 */
final class IngredientStrength
{
    public const MAX_LENGTH = 255;

    /**
     * @param  iterable<mixed>  $strengths  Per-ingredient strengths, in label order
     */
    public static function summarize(iterable $strengths): ?string
    {
        $parts = [];

        foreach ($strengths as $strength) {
            if (! is_string($strength) && ! is_numeric($strength)) {
                continue;
            }

            $value = trim((string) $strength);

            // Salt forms of one ingredient are often listed twice with the same
            // strength; repeating it adds no information.
            if ($value === '' || in_array($value, $parts, true)) {
                continue;
            }

            $parts[] = $value;
        }

        if ($parts === []) {
            return null;
        }

        return mb_substr(implode('; ', $parts), 0, self::MAX_LENGTH);
    }

    /**
     * @param  array<int, mixed>  $activeIngredients  openFDA `active_ingredients` entries
     */
    public static function fromOpenFdaIngredients(array $activeIngredients): ?string
    {
        return self::summarize(array_map(
            static fn (mixed $ingredient): mixed => is_array($ingredient)
                ? ($ingredient['strength'] ?? null)
                : null,
            $activeIngredients,
        ));
    }
}
