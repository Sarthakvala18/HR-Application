<?php

namespace Database\Factories;

use App\Enums\ProvisioningMode;
use App\Models\App;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AppFactory extends Factory
{
    protected $model = App::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'key' => Str::slug($name),
            'name' => ucfirst($name),
            'provisioning_mode' => ProvisioningMode::Automated,
            'supports_license_tiers' => false,
            'costs_money' => false,
            'offboard_priority' => 100,
            'active' => true,
        ];
    }

    public function manual(): static
    {
        return $this->state(['provisioning_mode' => ProvisioningMode::Manual]);
    }

    public function paid(): static
    {
        return $this->state([
            'costs_money' => true,
            'supports_license_tiers' => true,
            'license_tiers' => ['basic', 'pro'],
        ]);
    }
}
