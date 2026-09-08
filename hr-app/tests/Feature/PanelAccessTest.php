<?php

namespace Tests\Feature;

use App\Filament\Pages\AccessMatrix;
use App\Filament\Resources\Apps\AppResource;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\FormSubmissions\FormSubmissionResource;
use App\Filament\Resources\RoleTemplates\RoleTemplateResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Screen-level authorisation. These assert what each role can even reach,
 * which is the layer above the per-record policies.
 */
class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->{$role}()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_employee_role_cannot_reach_the_panel_at_all(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_hr_admin_reaches_the_dashboard(): void
    {
        $this->actingAsRole('hrAdmin');

        $this->get('/admin')->assertSuccessful();
    }

    public function test_inactive_users_are_locked_out_even_with_a_privileged_role(): void
    {
        $user = User::factory()->superAdmin()->create(['is_active' => false]);

        $this->actingAs($user)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_audit_log_is_restricted_to_admins(): void
    {
        $this->actingAsRole('superAdmin');
        $this->assertTrue(AuditLogResource::canAccess());

        $this->actingAsRole('hrAdmin');
        $this->assertTrue(AuditLogResource::canAccess());

        $this->actingAsRole('finance');
        $this->assertFalse(AuditLogResource::canAccess());

        $this->actingAsRole('manager');
        $this->assertFalse(AuditLogResource::canAccess());
    }

    public function test_the_audit_log_can_never_be_created_edited_or_deleted_from_the_ui(): void
    {
        $this->actingAsRole('superAdmin');

        $this->assertFalse(AuditLogResource::canCreate());
        $this->assertFalse(AuditLogResource::canEdit(new AuditLog));
        $this->assertFalse(AuditLogResource::canDelete(new AuditLog));
    }

    public function test_configuration_screens_are_restricted(): void
    {
        $this->actingAsRole('finance');

        $this->assertFalse(AppResource::canAccess());
        $this->assertFalse(RoleTemplateResource::canAccess());

        $this->actingAsRole('hrAdmin');

        $this->assertTrue(AppResource::canAccess());
        $this->assertTrue(RoleTemplateResource::canAccess());
    }

    public function test_only_super_admin_may_add_apps_to_the_catalog(): void
    {
        $this->actingAsRole('hrAdmin');
        $this->assertFalse(AppResource::canCreate());

        $this->actingAsRole('superAdmin');
        $this->assertTrue(AppResource::canCreate());
    }

    public function test_access_matrix_is_reachable_by_every_panel_role(): void
    {
        foreach (['superAdmin', 'hrAdmin', 'finance', 'manager'] as $role) {
            $this->actingAsRole($role);
            $this->assertTrue(AccessMatrix::canAccess(), "$role should reach the matrix");
        }
    }

    // ------------------------------------------------- review queue scoping

    public function test_bank_submissions_are_hidden_from_users_without_financial_access(): void
    {
        FormSubmission::factory()->create(['form_key' => FormSubmission::FORM_PAPERWORK]);
        FormSubmission::factory()->bank()->create();

        $this->actingAsRole('hrAdmin');
        $visibleToHr = FormSubmissionResource::getEloquentQuery()->pluck('form_key');

        $this->assertContains(FormSubmission::FORM_PAPERWORK, $visibleToHr);
        $this->assertNotContains(
            FormSubmission::FORM_BANK,
            $visibleToHr,
            'HR Admin must not see payout submissions',
        );

        $this->actingAsRole('finance');
        $visibleToFinance = FormSubmissionResource::getEloquentQuery()->pluck('form_key');

        $this->assertContains(FormSubmission::FORM_BANK, $visibleToFinance);
    }

    public function test_submissions_can_never_be_created_through_the_ui(): void
    {
        $this->actingAsRole('superAdmin');

        $this->assertFalse(FormSubmissionResource::canCreate());
    }

    // ---------------------------------------------------- directory scoping

    public function test_managers_only_see_their_own_reports_in_the_directory(): void
    {
        $managerEmployee = Employee::factory()->create();
        $report = Employee::factory()->create(['manager_id' => $managerEmployee->id]);
        $stranger = Employee::factory()->create();

        $user = User::factory()->manager()->create(['employee_id' => $managerEmployee->id]);
        $this->actingAs($user);

        $visible = EmployeeResource::getEloquentQuery()->pluck('id');

        $this->assertContains($report->id, $visible);
        $this->assertContains($managerEmployee->id, $visible, 'A manager should see their own record');
        $this->assertNotContains($stranger->id, $visible);
    }

    public function test_hr_admin_sees_the_whole_directory(): void
    {
        Employee::factory()->count(3)->create();

        $this->actingAsRole('hrAdmin');

        $this->assertSame(3, EmployeeResource::getEloquentQuery()->count());
    }
}
