<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'gtin' => fake()->unique()->numerify('##############'),
            'name' => fake()->words(3, true),
            'dosage_form' => fake()->randomElement(['tablet', 'capsule', 'solution', 'injection']),
            'strength' => fake()->randomElement(['10 mg', '20 mg', '50 mg/mL', '100 mg']),
            'ndc' => fake()->numerify('#####-###-##'),
            'is_active' => true,
        ];
    }
}
