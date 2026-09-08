<?php

namespace Database\Factories;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'personal_email' => fake()->unique()->safeEmail(),
            'work_email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'position' => fake()->jobTitle(),
            'employment_type' => EmploymentType::FullTime,
            'status' => EmployeeStatus::Active,
            'date_of_joining' => fake()->dateTimeBetween('-3 years', 'now'),
            'birthday' => fake()->dateTimeBetween('-45 years', '-22 years'),
            'salary_amount' => (string) fake()->numberBetween(250, 1500),
            'salary_currency' => 'USD',
            'country' => 'India',
            'timezone' => 'Asia/Kolkata',
        ];
    }

    public function exited(): static
    {
        return $this->state([
            'status' => EmployeeStatus::Exited,
            'date_of_exit' => now()->subDays(7),
        ]);
    }

    public function freelancer(): static
    {
        return $this->state(['employment_type' => EmploymentType::Freelancer]);
    }

    public function preOnboarding(): static
    {
        return $this->state([
            'status' => EmployeeStatus::PreOnboarding,
            'work_email' => null,
        ]);
    }
}
