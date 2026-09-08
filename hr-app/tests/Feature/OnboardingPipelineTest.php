<?php

namespace Tests\Feature;

use App\Enums\AccessStatus;
use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Enums\TaskStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\RoleTemplate;
use App\Models\User;
use App\Services\Process\OnboardingRunBuilder;
use App\Services\Process\ProcessTaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class OnboardingPipelineTest extends TestCase
{
    use RefreshDatabase;

    /** Seeded here so lookups in test arguments resolve against real data. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function hire(array $attributes = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'department_id' => Department::where('key', 'tech')->first()->id,
            'role_template_id' => RoleTemplate::where('key', 'tech')->first()->id,
            'employment_type' => EmploymentType::Freelancer,
            'status' => EmployeeStatus::PreOnboarding,
        ], $attributes));
    }

    public function test_the_pipeline_puts_paperwork_before_the_gate_and_provisioning_after_it(): void
    {
        $run = app(OnboardingRunBuilder::class)->build($this->hire());

        $keys = $run->tasks->pluck('key')->all();

        $gateIndex = array_search(OnboardingRunBuilder::GATE_KEY, $keys, true);

        $this->assertLessThan($gateIndex, array_search('contract', $keys, true));
        $this->assertGreaterThan($gateIndex, array_search('provision_google_workspace', $keys, true));
        $this->assertGreaterThan($gateIndex, array_search('welcome_post', $keys, true));
    }

    public function test_identity_backbone_is_provisioned_before_other_apps(): void
    {
        $run = app(OnboardingRunBuilder::class)->build($this->hire());

        $provisioning = $run->tasks
            ->filter(fn ($t) => str_starts_with($t->key, 'provision_'))
            ->pluck('key')
            ->values();

        $this->assertSame('provision_google_workspace', $provisioning->first());
        $this->assertSame('provision_bitwarden', $provisioning->get(1));
    }

    public function test_nothing_can_be_provisioned_before_the_signature(): void
    {
        $run = app(OnboardingRunBuilder::class)->build($this->hire());

        $google = $run->tasks->firstWhere('key', 'provision_google_workspace');

        $this->assertSame(TaskStatus::Blocked, $google->status);

        $this->expectException(RuntimeException::class);
        app(ProcessTaskRunner::class)->complete($google);
    }

    public function test_satisfying_the_gate_unblocks_provisioning(): void
    {
        $run = app(OnboardingRunBuilder::class)->build($this->hire());

        app(ProcessTaskRunner::class)->satisfySignatureGate($run);

        $run->refresh()->load('tasks');

        $this->assertSame(TaskStatus::Done, $run->tasks->firstWhere('key', 'contract')->status);
        $this->assertSame(TaskStatus::Done, $run->tasks->firstWhere('key', OnboardingRunBuilder::GATE_KEY)->status);
        $this->assertSame(TaskStatus::Pending, $run->tasks->firstWhere('key', 'provision_google_workspace')->status);
    }

    public function test_a_manual_step_cannot_be_completed_without_evidence(): void
    {
        $run = app(OnboardingRunBuilder::class)->build($this->hire());
        $runner = app(ProcessTaskRunner::class);
        $runner->satisfySignatureGate($run);

        $google = $run->refresh()->tasks->firstWhere('key', 'provision_google_workspace');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('evidence');

        $runner->complete($google);
    }

    public function test_completing_a_provisioning_step_creates_the_access_row_with_its_evidence(): void
    {
        $employee = $this->hire();
        $run = app(OnboardingRunBuilder::class)->build($employee);
        $runner = app(ProcessTaskRunner::class);
        $runner->satisfySignatureGate($run);

        $google = $run->refresh()->tasks->firstWhere('key', 'provision_google_workspace');
        $runner->complete($google, User::factory()->hrAdmin()->create(), 'aisha@example.com');

        $access = $employee->accesses()
            ->whereHas('app', fn ($q) => $q->where('key', 'google_workspace'))
            ->sole();

        $this->assertSame(AccessStatus::Active, $access->status);
        $this->assertSame('aisha@example.com', $access->external_id);
        $this->assertNotNull($access->granted_at);
    }

    public function test_licence_tier_from_the_role_template_lands_on_the_access_row(): void
    {
        $employee = $this->hire([
            'role_template_id' => RoleTemplate::where('key', 'client_manager')->first()->id,
        ]);
        $run = app(OnboardingRunBuilder::class)->build($employee);
        $runner = app(ProcessTaskRunner::class);
        $runner->satisfySignatureGate($run);

        $zoom = $run->refresh()->tasks->firstWhere('key', 'provision_zoom');
        $runner->complete($zoom, null, 'zoom-id-1');

        $access = $employee->accesses()
            ->whereHas('app', fn ($q) => $q->where('key', 'zoom'))
            ->sole();

        $this->assertSame('pro', $access->license_tier);
    }

    public function test_finishing_every_step_activates_the_person(): void
    {
        $employee = $this->hire();
        $run = app(OnboardingRunBuilder::class)->build($employee);
        $runner = app(ProcessTaskRunner::class);

        $runner->satisfySignatureGate($run);

        foreach ($run->refresh()->tasks as $task) {
            if (! $task->status->isFinished()) {
                $runner->complete($task->refresh(), null, 'done');
            }
        }

        $this->assertSame('completed', $run->refresh()->status);
        $this->assertSame(EmployeeStatus::Active, $employee->refresh()->status);
    }

    public function test_a_second_open_onboarding_run_is_refused(): void
    {
        $employee = $this->hire();
        app(OnboardingRunBuilder::class)->build($employee);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already has an open');

        app(OnboardingRunBuilder::class)->build($employee->refresh());
    }

    public function test_an_employee_without_a_role_template_gets_paperwork_but_no_provisioning(): void
    {
        $employee = $this->hire(['role_template_id' => null]);

        $run = app(OnboardingRunBuilder::class)->build($employee);
        $keys = $run->tasks->pluck('key');

        $this->assertContains('contract', $keys);
        $this->assertEmpty($keys->filter(fn ($k) => str_starts_with($k, 'provision_')));
    }

    public function test_full_time_hires_get_an_appointment_letter_not_a_freelance_contract(): void
    {
        $employee = $this->hire(['employment_type' => EmploymentType::FullTime]);

        $run = app(OnboardingRunBuilder::class)->build($employee);
        $contract = $run->tasks->firstWhere('key', 'contract');

        $this->assertSame('appointment', $contract->payload['document_type']);
        $this->assertStringContainsString('appointment letter', $contract->title);
    }

    public function test_skipping_a_step_records_the_reason(): void
    {
        $run = app(OnboardingRunBuilder::class)->build($this->hire());
        $runner = app(ProcessTaskRunner::class);

        $task = $run->tasks->firstWhere('key', 'bank_form');
        $runner->skip($task, null, 'Contractor is paid through an agency.');

        $this->assertSame(TaskStatus::Skipped, $task->refresh()->status);
        $this->assertSame('Contractor is paid through an agency.', $task->evidence);
    }
}
