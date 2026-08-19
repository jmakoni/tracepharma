<?php

namespace Database\Factories;

use App\Enums\PartnerType;
use App\Models\TradingPartner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TradingPartner>
 */
class TradingPartnerFactory extends Factory
{
    protected $model = TradingPartner::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => fake()->randomElement(PartnerType::cases()),
            'country_code' => 'US',
            'is_active' => true,
        ];
    }
}
