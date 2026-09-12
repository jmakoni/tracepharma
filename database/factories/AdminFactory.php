<?php

namespace Database\Factories;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Admin>
 */
class AdminFactory extends Factory
{
    protected static ?string $password;

    protected $model = Admin::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
            'failed_login_count' => 0,
            'session_version' => 0,
            'remember_token' => Str::random(10),
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'disabled_at' => now(),
        ]);
    }

    public function locked(): static
    {
        return $this->state(fn (array $attributes) => [
            'failed_login_count' => (int) config('tracepharma.account_security.max_failed_logins', 5),
            'locked_until' => now()->addMinutes(15),
        ]);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn (array $attributes) => [
            'must_change_password' => true,
        ]);
    }

    /**
     * Populate Entra/AD-style directory attributes.
     */
    public function directory(): static
    {
        return $this->state(fn (array $attributes) => [
            'directory_object_id' => (string) Str::uuid(),
            'user_principal_name' => $attributes['email'] ?? fake()->unique()->userName().'@contoso.test',
            'employee_id' => (string) fake()->numerify('E######'),
            'given_name' => fake()->firstName(),
            'surname' => fake()->lastName(),
            'job_title' => fake()->jobTitle(),
            'department' => fake()->randomElement(['Platform', 'Support', 'Engineering']),
            'company_name' => fake()->company(),
            'office_location' => fake()->city(),
            'mobile_phone' => fake()->e164PhoneNumber(),
            'business_phone' => fake()->phoneNumber(),
            'directory_groups' => ['sg-platform-admins'],
            'directory_synced_at' => now(),
        ]);
    }
}
