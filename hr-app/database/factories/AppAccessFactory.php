<?php

namespace Database\Factories;

use App\Enums\AccessStatus;
use App\Models\App;
use App\Models\AppAccess;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class AppAccessFactory extends Factory
{
    protected $model = AppAccess::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'app_id' => App::factory(),
            'status' => AccessStatus::Active,
            'granted_at' => now()->subMonth(),
        ];
    }

    public function revoked(): static
    {
        return $this->state([
            'status' => AccessStatus::Revoked,
            'revoked_at' => now(),
        ]);
    }
}
