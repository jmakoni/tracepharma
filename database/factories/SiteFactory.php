<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\TradingPartner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        return [
            'trading_partner_id' => TradingPartner::factory(),
            'name' => fake()->company().' Pharmacy',
            'code' => fake()->unique()->bothify('SITE-###'),
            'gln' => fake()->unique()->numerify('#############'),
            'street_address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'zipcode' => fake()->postcode(),
            'country_code' => 'US',
            'is_headquarters' => false,
            'is_active' => true,
            'is_organization_facility' => false,
        ];
    }

    public function owned(): static
    {
        return $this->state(fn (array $attributes) => [
            'trading_partner_id' => null,
            'is_organization_facility' => true,
            'code' => 'TEST-'.fake()->unique()->bothify('??##'),
        ]);
    }
}
