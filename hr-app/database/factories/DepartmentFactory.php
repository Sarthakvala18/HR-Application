<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DepartmentFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'key' => Str::slug($name),
            'name' => ucfirst($name),
            'offboard_destination_email' => 'admin@example.com',
            'offboard_to_manager_first' => false,
            'active' => true,
        ];
    }

    public function marketing(): static
    {
        return $this->state([
            'key' => 'marketing',
            'name' => 'Marketing',
            'offboard_to_manager_first' => true,
            'offboard_destination_email' => 'admin@example.com',
        ]);
    }
}
