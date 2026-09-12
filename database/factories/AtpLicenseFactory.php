<?php

namespace Database\Factories;

use App\Enums\FacilityType;
use App\Models\AtpLicense;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AtpLicense>
 */
class AtpLicenseFactory extends Factory
{
    protected $model = AtpLicense::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'facility_type' => fake()->randomElement(FacilityType::cases()),
            'license_number' => fake()->unique()->bothify('ATP-####'),
            'license_country' => 'US',
            'license_state' => fake()->stateAbbr(),
            'license_expiration_date' => fake()->dateTimeBetween('+4 months', '+3 years'),
            'reporting_year' => (int) now()->year,
            'facility_contact_person' => fake()->optional()->name(),
            'facility_contact_email' => fake()->optional()->safeEmail(),
            'is_active' => true,
        ];
    }

    /**
     * A license with no expiration date: present, but not provably in force.
     */
    public function unknownExpiry(): static
    {
        return $this->state(fn (): array => ['license_expiration_date' => null]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'license_expiration_date' => now()->subDay()->toDateString(),
        ]);
    }

    public function expiringSoon(): static
    {
        return $this->state(fn (): array => [
            'license_expiration_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
