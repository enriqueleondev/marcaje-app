<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name_paternal' => fake()->lastName(),
            'last_name_maternal' => fake()->lastName(),
            'enrollment_number' => (string) fake()->unique()->numberBetween(20250000, 20259999),
        ];
    }
}
