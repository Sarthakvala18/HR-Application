<?php

namespace Tests\Feature;

use App\Enums\AccessStatus;
use App\Models\App;
use App\Models\AppAccess;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_exited_employees_holding_live_access_are_surfaced(): void
    {
        // Arrange: the exact situation manual offboarding leaves behind.
        $leaver = Employee::factory()->exited()->create();
        AppAccess::factory()->create([
            'employee_id' => $leaver->id,
            'status' => AccessStatus::Active,
        ]);

        $cleanLeaver = Employee::factory()->exited()->create();
        AppAccess::factory()->revoked()->create(['employee_id' => $cleanLeaver->id]);

        // Act
        $flagged = Employee::withLingeringAccess()->pluck('id');

        // Assert
        $this->assertTrue($flagged->contains($leaver->id));
        $this->assertFalse($flagged->contains($cleanLeaver->id));
    }

    public function test_revoke_pending_still_counts_as_live_access(): void
    {
        $leaver = Employee::factory()->exited()->create();
        AppAccess::factory()->create([
            'employee_id' => $leaver->id,
            'status' => AccessStatus::RevokePending,
        ]);

        $this->assertSame(1, Employee::withLingeringAccess()->count());
    }

    public function test_billable_seat_requires_a_paid_app_a_tier_and_live_status(): void
    {
        $paidApp = App::factory()->paid()->create();
        $freeApp = App::factory()->create();

        $billable = AppAccess::factory()->create([
            'app_id' => $paidApp->id,
            'status' => AccessStatus::Active,
            'license_tier' => 'pro',
        ]);
        $noTier = AppAccess::factory()->create([
            'app_id' => $paidApp->id,
            'status' => AccessStatus::Active,
            'license_tier' => null,
        ]);
        $freeSeat = AppAccess::factory()->create([
            'app_id' => $freeApp->id,
            'status' => AccessStatus::Active,
            'license_tier' => 'basic',
        ]);
        $revoked = AppAccess::factory()->revoked()->create([
            'app_id' => $paidApp->id,
            'license_tier' => 'pro',
        ]);

        $this->assertTrue($billable->isBillableSeat());
        $this->assertFalse($noTier->isBillableSeat());
        $this->assertFalse($freeSeat->isBillableSeat());
        $this->assertFalse($revoked->isBillableSeat());
    }

    public function test_offboarding_destination_follows_the_department_matrix(): void
    {
        // Tech routes to the services account.
        $tech = Department::factory()->create([
            'key' => 'tech',
            'offboard_destination_email' => 'services@example.com',
        ]);
        $techPerson = Employee::factory()->create(['department_id' => $tech->id]);

        $this->assertSame('services@example.com', $techPerson->offboardDestinationEmail());
    }

    public function test_marketing_routes_to_the_manager_but_falls_back_to_admin_for_the_manager(): void
    {
        $marketing = Department::factory()->marketing()->create();

        $head = Employee::factory()->create([
            'department_id' => $marketing->id,
            'work_email' => 'head@example.com',
            'manager_id' => null,
        ]);
        $report = Employee::factory()->create([
            'department_id' => $marketing->id,
            'manager_id' => $head->id,
        ]);

        // Below manager -> goes to the manager.
        $this->assertSame('head@example.com', $report->offboardDestinationEmail());
        // The manager themselves -> falls back to admin.
        $this->assertSame('admin@example.com', $head->offboardDestinationEmail());
    }

    public function test_only_hr_admin_and_super_admin_may_trigger_offboarding(): void
    {
        $employee = Employee::factory()->create();

        $this->assertTrue(User::factory()->superAdmin()->create()->can('runOffboarding', $employee));
        $this->assertTrue(User::factory()->hrAdmin()->create()->can('runOffboarding', $employee));
        $this->assertFalse(User::factory()->finance()->create()->can('runOffboarding', $employee));
        $this->assertFalse(User::factory()->manager()->create()->can('runOffboarding', $employee));
    }

    public function test_employee_role_cannot_open_the_admin_panel(): void
    {
        $this->assertFalse(User::factory()->create()->role->canAccessPanel());
        $this->assertTrue(User::factory()->hrAdmin()->create()->role->canAccessPanel());
    }
}
