<?php

namespace Database\Factories;

use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'grade' => fake()->numberBetween(1, 3),
            'number' => fake()->unique()->numberBetween(1, 200),
        ];
    }
}
