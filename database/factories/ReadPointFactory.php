<?php

namespace Database\Factories;

use App\Models\ReadPoint;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReadPoint>
 */
class ReadPointFactory extends Factory
{
    protected $model = ReadPoint::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => fake()->randomElement(['Receiving Dock', 'Pharmacy Counter', 'Quarantine Cage']),
            'code' => fake()->bothify('RP-##'),
            'sgln' => fake()->numerify('############.#.#'),
            'is_active' => true,
        ];
    }
}
