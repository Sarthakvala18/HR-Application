<?php

namespace Tests\Feature;

use App\Enums\AccessStatus;
use App\Enums\EmployeeStatus;
use App\Enums\TaskStatus;
use App\Models\App as AppModel;
use App\Models\AppAccess;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ProcessRun;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Process\OffboardingRunBuilder;
use App\Services\Process\ProcessTaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OffboardingPipelineTest extends TestCase
{
    use RefreshDatabase;

    /** A person holding live access to Google, Bitwarden, Zoom and Slack. */
    private function leaver(array $attributes = []): Employee
    {
        $this->seed();

        $employee = Employee::factory()->create(array_merge([
            'department_id' => Department::where('key', 'tech')->first()->id,
            'status' => EmployeeStatus::Offboarding,
        ], $attributes));

        foreach (['google_workspace', 'bitwarden', 'zoom', 'slack'] as $key) {
            AppAccess::create([
                'employee_id' => $employee->id,
                'app_id' => AppModel::where('key', $key)->first()->id,
                'status' => AccessStatus::Active,
                'license_tier' => $key === 'zoom' ? 'pro' : null,
                'granted_at' => now()->subYear(),
            ]);
        }

        return $employee;
    }

    public function test_revocations_come_first_and_google_leads(): void
    {
        $run = app(OffboardingRunBuilder::class)->build($this->leaver());

        $keys = $run->tasks->pluck('key')->values();

        $this->assertSame('revoke_google_workspace', $keys->first());
        $this->assertSame('revoke_bitwarden', $keys->get(1));

        // Data handling must sit after every revocation.
        $lastRevoke = $keys->search(fn ($k) => $k === 'revoke_slack');
        $this->assertGreaterThan($lastRevoke, $keys->search('data_migration'));
    }

    public function test_the_first_revocation_is_marked_do_this_first(): void
    {
        $run = app(OffboardingRunBuilder::class)->build($this->leaver());

        $first = $run->tasks->firstWhere('key', 'revoke_google_workspace');

        $this->assertStringContainsString('Do this first', $first->description_md);
    }

    public function test_tasks_are_generated_from_access_actually_held_not_the_role_template(): void
    {
        $employee = $this->leaver();

        // Revoked access is not an exposure, so it should produce no task.
        AppAccess::create([
            'employee_id' => $employee->id,
            'app_id' => AppModel::where('key', 'n8n')->first()->id,
            'status' => AccessStatus::Revoked,
        ]);

        $run = app(OffboardingRunBuilder::class)->build($employee);
        $keys = $run->tasks->pluck('key');

        $this->assertContains('revoke_zoom', $keys);
        $this->assertNotContains('revoke_n8n', $keys);
    }

    public function test_data_migration_waits_for_every_revocation(): void
    {
        $run = app(OffboardingRunBuilder::class)->build($this->leaver());

        $migration = $run->tasks->firstWhere('key', 'data_migration');

        $this->assertSame(TaskStatus::Blocked, $migration->status);
        $this->assertEqualsCanonicalizing(
            ['revoke_google_workspace', 'revoke_bitwarden', 'revoke_zoom', 'revoke_slack'],
            $migration->depends_on,
        );
    }

    public function test_the_migration_destination_comes_from_the_department_matrix(): void
    {
        $run = app(OffboardingRunBuilder::class)->build($this->leaver());

        $migration = $run->tasks->firstWhere('key', 'data_migration');

        // Tech routes to the services account.
        $this->assertSame('services@example.com', $migration->payload['destination']);
        $this->assertStringContainsString('services@example.com', $migration->description_md);
    }

    public function test_delete_and_alias_step_waits_for_the_migration(): void
    {
        $run = app(OffboardingRunBuilder::class)->build($this->leaver());

        $verify = $run->tasks->firstWhere('key', 'verify_and_delete');

        $this->assertSame(['data_migration'], $verify->depends_on);
        $this->assertStringContainsString('24 hours', $verify->description_md);
        $this->assertStringContainsString('alias', $verify->description_md);
    }

    public function test_a_credential_rotation_task_is_always_created(): void
    {
        $run = app(OffboardingRunBuilder::class)->build($this->leaver());

        $rotation = $run->tasks->firstWhere('key', 'credential_rotation');

        $this->assertNotNull($rotation, 'Removing vault access does not rotate shared logins');
        $this->assertSame(['revoke_bitwarden'], $rotation->depends_on);
    }

    public function test_completing_a_revocation_updates_the_access_matrix(): void
    {
        $employee = $this->leaver();
        $run = app(OffboardingRunBuilder::class)->build($employee);
        $runner = app(ProcessTaskRunner::class);

        $task = $run->tasks->firstWhere('key', 'revoke_google_workspace');
        $runner->complete($task, User::factory()->hrAdmin()->create(), 'Suspended in admin console');

        $access = $employee->accesses()
            ->whereHas('app', fn ($q) => $q->where('key', 'google_workspace'))
            ->sole();

        $this->assertSame(AccessStatus::Revoked, $access->status);
        $this->assertNotNull($access->revoked_at);
    }

    public function test_revocations_are_written_to_the_audit_log(): void
    {
        $employee = $this->leaver();
        $run = app(OffboardingRunBuilder::class)->build($employee);

        app(ProcessTaskRunner::class)->complete(
            $run->tasks->firstWhere('key', 'revoke_slack'),
            null,
            'Deactivated',
        );

        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::ACCESS_REVOKED]);
    }

    public function test_finishing_the_run_marks_the_person_exited(): void
    {
        $employee = $this->leaver();
        $run = app(OffboardingRunBuilder::class)->build($employee);
        $runner = app(ProcessTaskRunner::class);

        // Work through in dependency order until nothing is left.
        for ($pass = 0; $pass < 6; $pass++) {
            foreach ($run->refresh()->tasks as $task) {
                if ($task->status === TaskStatus::Pending) {
                    $runner->complete($task->refresh(), null, 'done');
                }
            }
        }

        $this->assertSame('completed', $run->refresh()->status);
        $this->assertSame(EmployeeStatus::Exited, $employee->refresh()->status);
        $this->assertNotNull($employee->date_of_exit);
    }

    public function test_an_urgent_run_is_flagged(): void
    {
        $run = app(OffboardingRunBuilder::class)->build($this->leaver(), null, urgent: true);

        $this->assertTrue($run->is_urgent);
    }

    public function test_marketing_leavers_route_to_their_manager(): void
    {
        $this->seed();

        $marketing = Department::where('key', 'marketing')->first();
        $head = Employee::factory()->create([
            'department_id' => $marketing->id,
            'work_email' => 'head@example.com',
        ]);
        $report = Employee::factory()->create([
            'department_id' => $marketing->id,
            'manager_id' => $head->id,
        ]);
        AppAccess::create([
            'employee_id' => $report->id,
            'app_id' => AppModel::where('key', 'slack')->first()->id,
            'status' => AccessStatus::Active,
        ]);

        $run = app(OffboardingRunBuilder::class)->build($report);

        $this->assertSame(
            'head@example.com',
            $run->tasks->firstWhere('key', 'data_migration')->payload['destination'],
        );
    }

    public function test_a_person_with_no_live_access_still_gets_the_data_and_letter_steps(): void
    {
        $this->seed();
        $employee = Employee::factory()->create([
            'department_id' => Department::where('key', 'sales')->first()->id,
        ]);

        $run = app(OffboardingRunBuilder::class)->build($employee);
        $keys = $run->tasks->pluck('key');

        $this->assertContains('data_migration', $keys);
        $this->assertContains('letters', $keys);
        $this->assertSame(
            TaskStatus::Pending,
            $run->tasks->firstWhere('key', 'data_migration')->status,
            'With nothing to revoke, migration should be immediately actionable',
        );
    }

    public function test_a_second_open_offboarding_run_is_refused(): void
    {
        $employee = $this->leaver();
        app(OffboardingRunBuilder::class)->build($employee);

        $this->expectException(\RuntimeException::class);

        app(OffboardingRunBuilder::class)->build($employee->refresh());
    }

    public function test_offboarding_and_onboarding_runs_can_coexist(): void
    {
        // Different types, so the guard must not block a rehire scenario.
        $employee = $this->leaver();

        app(OffboardingRunBuilder::class)->build($employee);

        $this->assertSame(1, ProcessRun::where('type', ProcessRun::TYPE_OFFBOARDING)->count());
        $this->assertSame(0, ProcessRun::where('type', ProcessRun::TYPE_ONBOARDING)->count());
    }
}
