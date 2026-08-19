<?php

namespace Database\Factories;

use App\Models\LocationDevice;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LocationDevice>
 */
class LocationDeviceFactory extends Factory
{
    protected $model = LocationDevice::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => fake()->words(2, true).' Location',
            'description' => fake()->optional()->sentence(),
            'gln' => fake()->unique()->numerify('#############'),
            'altitude' => fake()->optional()->randomFloat(2, 0, 2000),
            'latitude' => fake()->optional()->latitude(),
            'longitude' => fake()->optional()->longitude(),
            'logo' => null,
        ];
    }
}
