<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeePaymentDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeePaymentDetailFactory extends Factory
{
    protected $model = EmployeePaymentDetail::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'payout_currency' => 'USD',
            'bank_country' => 'India',
            'account_number' => (string) fake()->numerify('###########'),
            'swift_code' => 'HDFCINBB',
            'address_line1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'address_country' => 'India',
            'source' => 'manual',
        ];
    }
}
