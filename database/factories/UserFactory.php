<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Nimal Perera', 'Kumari Fernando', 'Suresh Rajan']),
            'role' => UserRole::Sales,
            'is_active' => true,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::Admin]);
    }

    public function sales(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::Sales]);
    }

    public function stock(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::Stock]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function withAppAuthentication(): static
    {
        return $this->state(function (): array {
            $provider = AppAuthentication::make();

            return [
                'app_authentication_secret' => $provider->generateSecret(),
                'app_authentication_recovery_codes' => array_map(
                    fn (string $code): string => Hash::make($code),
                    $provider->generateRecoveryCodes(),
                ),
            ];
        });
    }
}
