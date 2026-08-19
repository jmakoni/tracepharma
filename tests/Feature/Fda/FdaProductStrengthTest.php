<?php

namespace Tests\Feature\Fda;

use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductActiveIngredient;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A combination product carries a strength per active ingredient. Showing only the first
 * understates the package — "5 mg/1" for a 5 mg / 160 mg tablet — and makes two different
 * combinations look identical on a receiving screen.
 */
class FdaProductStrengthTest extends TestCase
{
    /** @var list<int> */
    private array $fdaProductIds = [];

    protected function tearDown(): void
    {
        if ($this->fdaProductIds !== []) {
            FdaProductActiveIngredient::query()->whereIn('product_id_fk', $this->fdaProductIds)->delete();
            FdaProduct::query()->whereIn('id', $this->fdaProductIds)->delete();
            $this->fdaProductIds = [];
        }

        parent::tearDown();
    }

    #[Test]
    public function it_summarizes_every_active_ingredient_strength(): void
    {
        $product = $this->fdaProduct([
            ['name' => 'AMLODIPINE BESYLATE', 'strength' => '5 mg/1'],
            ['name' => 'VALSARTAN', 'strength' => '160 mg/1'],
        ]);

        $this->assertSame('5 mg/1; 160 mg/1', $product->activeIngredientStrength());

        // Eager-loaded rows must read the same as a fresh query.
        $this->assertSame(
            '5 mg/1; 160 mg/1',
            FdaProduct::query()->with('activeIngredients')->findOrFail($product->getKey())->activeIngredientStrength(),
        );
    }

    #[Test]
    public function a_single_ingredient_product_keeps_its_only_strength(): void
    {
        $product = $this->fdaProduct([
            ['name' => 'ATORVASTATIN CALCIUM', 'strength' => '20 mg/1'],
        ]);

        $this->assertSame('20 mg/1', $product->activeIngredientStrength());
    }

    #[Test]
    public function a_product_with_no_ingredient_strengths_has_none(): void
    {
        $product = $this->fdaProduct([]);

        $this->assertNull($product->activeIngredientStrength());
    }

    /**
     * @param  list<array{name: string, strength: ?string}>  $ingredients
     */
    private function fdaProduct(array $ingredients): FdaProduct
    {
        $product = FdaProduct::query()->create([
            'product_id' => 'TEST-STRENGTH-'.uniqid(),
            'product_ndc' => fake()->unique()->numerify('#####-###'),
            'brand_name' => 'Strength Test Rx',
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION,
            'finished' => true,
        ]);
        $this->fdaProductIds[] = (int) $product->getKey();

        foreach ($ingredients as $ingredient) {
            FdaProductActiveIngredient::query()->create([
                'product_id_fk' => $product->getKey(),
                'name' => $ingredient['name'],
                'strength' => $ingredient['strength'],
            ]);
        }

        return $product;
    }
}
