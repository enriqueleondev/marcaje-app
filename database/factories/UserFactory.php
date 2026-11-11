<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'role' => Role::Teacher,
            'first_name' => fake()->firstName(),
            'last_name_paternal' => fake()->lastName(),
            'last_name_maternal' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password123'),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => Role::Admin]);
    }

    public function teacher(): static
    {
        return $this->state(['role' => Role::Teacher]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /** Cuenta con contraseña temporal vigente (o vencida si $expired). */
    public function withTemporaryPassword(bool $expired = false): static
    {
        return $this->state([
            'must_change_password' => true,
            'password_expires_at' => $expired ? now()->subHour() : now()->addHours(User::TEMPORARY_PASSWORD_HOURS),
        ]);
    }
}
