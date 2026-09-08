<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeePaymentDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentDetailSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_number_is_stored_as_ciphertext_not_plaintext(): void
    {
        // Arrange
        $detail = EmployeePaymentDetail::factory()->create(['account_number' => '937223675']);

        // Act
        $stored = DB::table('employee_payment_details')->where('id', $detail->id)->value('account_number');

        // Assert
        $this->assertNotSame('937223675', $stored);
        $this->assertStringNotContainsString('937223675', $stored);
        $this->assertSame('937223675', $detail->fresh()->account_number);
    }

    public function test_salary_is_stored_as_ciphertext(): void
    {
        $employee = Employee::factory()->create(['salary_amount' => '1200']);

        $stored = DB::table('employees')->where('id', $employee->id)->value('salary_amount');

        $this->assertNotSame('1200', $stored);
        $this->assertSame('1200', $employee->fresh()->salary_amount);
    }

    public function test_last4_is_derived_on_save_for_masked_display(): void
    {
        $detail = EmployeePaymentDetail::factory()->create(['account_number' => '50100000123456']);

        $this->assertSame('3456', $detail->account_last4);
        $this->assertSame('•••• 3456', $detail->maskedAccountNumber());
    }

    public function test_last4_updates_when_account_number_changes(): void
    {
        $detail = EmployeePaymentDetail::factory()->create(['account_number' => '11112222']);

        $detail->update(['account_number' => '99998888']);

        $this->assertSame('8888', $detail->fresh()->account_last4);
    }

    public function test_secrets_are_excluded_from_array_serialisation(): void
    {
        $detail = EmployeePaymentDetail::factory()->create();

        $serialised = $detail->toArray();

        foreach (['account_number', 'iban', 'swift_code', 'address_line1', 'city'] as $secret) {
            $this->assertArrayNotHasKey($secret, $serialised, "$secret leaked into toArray()");
        }
    }

    public function test_finance_and_super_admin_may_reveal_but_hr_admin_may_not(): void
    {
        $detail = EmployeePaymentDetail::factory()->create();

        $this->assertTrue(User::factory()->superAdmin()->create()->can('reveal', $detail));
        $this->assertTrue(User::factory()->finance()->create()->can('reveal', $detail));
        $this->assertFalse(User::factory()->hrAdmin()->create()->can('reveal', $detail));
        $this->assertFalse(User::factory()->manager()->create()->can('reveal', $detail));
    }

    public function test_an_employee_may_reveal_only_their_own_payout_details(): void
    {
        $mine = EmployeePaymentDetail::factory()->create();
        $theirs = EmployeePaymentDetail::factory()->create();

        $user = User::factory()->create(['employee_id' => $mine->employee_id]);

        $this->assertTrue($user->can('reveal', $mine));
        $this->assertFalse($user->can('reveal', $theirs));
    }

    public function test_only_super_admin_may_export_payment_data(): void
    {
        $this->assertTrue(User::factory()->superAdmin()->create()->can('export', EmployeePaymentDetail::class));
        $this->assertFalse(User::factory()->finance()->create()->can('export', EmployeePaymentDetail::class));
        $this->assertFalse(User::factory()->hrAdmin()->create()->can('export', EmployeePaymentDetail::class));
    }
}
