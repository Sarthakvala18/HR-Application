<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_is_stripped_at_the_model_layer_for_hr_admin(): void
    {
        // Arrange: HR Admin runs hiring but is not allowed to see pay.
        $employee = Employee::factory()->create(['salary_amount' => '900']);
        $hrAdmin = User::factory()->hrAdmin()->create();

        // Act
        $visible = $employee->toVisibleArray($hrAdmin);

        // Assert
        $this->assertArrayNotHasKey('salary_amount', $visible);
        $this->assertContains('salary_amount', $visible['_redacted']);
    }

    public function test_finance_sees_salary_but_not_hr_notes(): void
    {
        $employee = Employee::factory()->create([
            'salary_amount' => '900',
            'notes' => 'performance concern raised in June',
        ]);

        $visible = $employee->toVisibleArray(User::factory()->finance()->create());

        $this->assertSame('900', $visible['salary_amount']);
        $this->assertArrayNotHasKey('notes', $visible);
    }

    public function test_super_admin_sees_everything(): void
    {
        $employee = Employee::factory()->create(['salary_amount' => '900', 'notes' => 'note']);

        $visible = $employee->toVisibleArray(User::factory()->superAdmin()->create());

        $this->assertSame([], $visible['_redacted']);
    }

    public function test_manager_sees_neither_salary_nor_personal_contact_details(): void
    {
        $employee = Employee::factory()->create();
        $manager = User::factory()->manager()->create();

        $visible = $employee->toVisibleArray($manager);

        $this->assertArrayNotHasKey('salary_amount', $visible);
        $this->assertArrayNotHasKey('personal_email', $visible);
        $this->assertArrayNotHasKey('phone', $visible);
    }

    public function test_employees_can_see_their_own_contact_details_but_not_their_salary(): void
    {
        $employee = Employee::factory()->create();
        $self = User::factory()->create(['employee_id' => $employee->id]);

        $visible = $employee->toVisibleArray($self);

        $this->assertArrayHasKey('personal_email', $visible);
        $this->assertArrayNotHasKey('salary_amount', $visible);
    }

    public function test_a_guest_sees_nothing_sensitive(): void
    {
        $employee = Employee::factory()->create();

        $visible = $employee->toVisibleArray(null);

        $this->assertArrayNotHasKey('salary_amount', $visible);
        $this->assertArrayNotHasKey('personal_email', $visible);
        $this->assertArrayNotHasKey('notes', $visible);
    }

    public function test_can_be_seen_by_answers_per_attribute(): void
    {
        $employee = Employee::factory()->create();
        $finance = User::factory()->finance()->create();

        $this->assertTrue($employee->canBeSeenBy($finance, 'salary_amount'));
        $this->assertFalse($employee->canBeSeenBy($finance, 'notes'));
        // Non-sensitive attributes are always visible.
        $this->assertTrue($employee->canBeSeenBy($finance, 'full_name'));
    }
}
