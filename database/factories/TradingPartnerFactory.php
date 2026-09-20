<?php

namespace Database\Factories;

use App\Enums\EpcisGuideline;
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
            'epcis_guideline' => EpcisGuideline::R12,
            'country_code' => 'US',
            'is_active' => true,
        ];
    }

    public function r12(): static
    {
        return $this->state(fn (): array => [
            'epcis_guideline' => EpcisGuideline::R12,
        ]);
    }

    public function r13(): static
    {
        return $this->state(fn (): array => [
            'epcis_guideline' => EpcisGuideline::R13,
        ]);
    }
}
