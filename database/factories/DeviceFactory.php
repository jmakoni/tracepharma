<?php

namespace Database\Factories;

use App\Enums\DeviceType;
use App\Models\Device;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true).' Device',
            'device_type' => fake()->randomElement(DeviceType::cases()),
            'manufacturer' => fake()->company(),
            'model' => fake()->bothify('MDL-###'),
            'serial_number' => fake()->unique()->bothify('SN########'),
            'site_id' => Site::factory(),
            'is_active' => true,
        ];
    }
}
