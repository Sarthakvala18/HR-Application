<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Employee,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function role(UserRole $role): static
    {
        return $this->state(['role' => $role]);
    }

    public function superAdmin(): static
    {
        return $this->role(UserRole::SuperAdmin);
    }

    public function hrAdmin(): static
    {
        return $this->role(UserRole::HrAdmin);
    }

    public function finance(): static
    {
        return $this->role(UserRole::Finance);
    }

    public function manager(): static
    {
        return $this->role(UserRole::Manager);
    }
}
