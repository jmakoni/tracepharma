<?php

namespace Tests\Unit\Catalog;

use App\Support\Catalog\IngredientStrength;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class IngredientStrengthTest extends TestCase
{
    #[Test]
    public function a_single_ingredient_keeps_the_strength_it_was_given(): void
    {
        $this->assertSame('10 mg/1', IngredientStrength::summarize(['10 mg/1']));
    }

    #[Test]
    public function every_ingredient_strength_is_kept_in_label_order(): void
    {
        $this->assertSame(
            '5 mg/1; 160 mg/1',
            IngredientStrength::summarize(['5 mg/1', '160 mg/1']),
        );
    }

    #[Test]
    public function blank_and_repeated_strengths_are_dropped(): void
    {
        $this->assertSame(
            '500 mg/1; 125 mg/1',
            IngredientStrength::summarize(['500 mg/1', '  ', null, '500 mg/1', ' 125 mg/1 ']),
        );
    }

    #[Test]
    public function a_product_with_no_usable_strength_has_none(): void
    {
        $this->assertNull(IngredientStrength::summarize([]));
        $this->assertNull(IngredientStrength::summarize([null, '', ' ', []]));
    }

    #[Test]
    public function the_summary_fits_the_catalog_column(): void
    {
        $summary = IngredientStrength::summarize(array_map(
            static fn (int $i): string => "{$i}00 mg/1",
            range(1, 60),
        ));

        $this->assertNotNull($summary);
        $this->assertSame(IngredientStrength::MAX_LENGTH, mb_strlen($summary));
    }

    #[Test]
    public function openfda_ingredient_entries_are_read_by_strength(): void
    {
        $this->assertSame(
            '5 mg/1; 160 mg/1',
            IngredientStrength::fromOpenFdaIngredients([
                ['name' => 'AMLODIPINE BESYLATE', 'strength' => '5 mg/1'],
                ['name' => 'VALSARTAN', 'strength' => '160 mg/1'],
                ['name' => 'NO STRENGTH LISTED'],
                'not an ingredient row',
            ]),
        );
    }
}
