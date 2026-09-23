<?php

namespace Tests\Feature;

use App\Enums\AccessStatus;
use App\Enums\EmployeeStatus;
use App\Enums\TaskStatus;
use App\Filament\Resources\ProcessRuns\Pages\ViewProcessRun;
use App\Filament\Resources\ProcessRuns\RelationManagers\TasksRelationManager;
use App\Mail\ExitLetterMail;
use App\Models\App as AppModel;
use App\Models\AppAccess;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ProcessRun;
use App\Models\ProcessTask;
use App\Models\User;
use App\Services\Process\OffboardingRunBuilder;
use App\Services\Process\ProcessTaskRunner;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\MakesLetterhead;
use Tests\TestCase;

/**
 * The screen HR actually uses to send exit letters.
 *
 * Sending cannot be undone, so the parts that matter here are: the preview
 * works before anything is sent, a gap in the record is asked for rather than
 * silently left blank, and the step cannot be ticked unless the mail went.
 */
class LetterSendScreenTest extends TestCase
{
    use MakesLetterhead;
    use RefreshDatabase;

    private ProcessRun $run;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeLetterhead();
        $this->seed();

        $this->employee = Employee::factory()->create([
            'full_name' => 'Departing Person',
            'department_id' => Department::where('key', 'tech')->first()->id,
            'status' => EmployeeStatus::Offboarding,
            'personal_email' => 'leaver@example.com',
            'employee_code' => 'CF-7001',
            'position' => 'Engineer',
            'date_of_joining' => '2023-05-02',
            'date_of_exit' => null,
            'manager_id' => Employee::factory()->create(['full_name' => 'Their Lead'])->id,
        ]);

        AppAccess::create([
            'employee_id' => $this->employee->id,
            'app_id' => AppModel::where('key', 'slack')->first()->id,
            'status' => AccessStatus::Active,
            'granted_at' => now()->subYear(),
        ]);

        $this->run = app(OffboardingRunBuilder::class)->build($this->employee);

        $this->actingAs(User::factory()->hrAdmin()->create());
    }

    private function lettersTask(): ProcessTask
    {
        return $this->run->tasks()->where('key', ProcessTaskRunner::LETTERS_KEY)->sole();
    }

    /**
     * Finishes everything the letters step waits on.
     *
     * The step is Blocked until access is revoked and data is handled, which is
     * the point of the pipeline — so a test about sending has to earn its way
     * there rather than reach past the dependencies.
     */
    private function unblockLetters(): void
    {
        $runner = app(ProcessTaskRunner::class);

        // Dependencies are transitive, so walk the run in passes until the
        // letters step becomes startable rather than assuming one level.
        for ($pass = 0; $pass < 6; $pass++) {
            if ($this->lettersTask()->status === TaskStatus::Pending) {
                return;
            }

            foreach ($this->run->refresh()->tasks as $task) {
                if ($task->key === ProcessTaskRunner::LETTERS_KEY) {
                    continue;
                }

                if ($task->status === TaskStatus::Pending) {
                    $runner->complete($task->refresh(), null, 'done in test');
                }
            }
        }

        $this->fail('Could not reach the letters step: it is still '.$this->lettersTask()->status->value.'.');
    }

    private function screen(): Testable
    {
        return Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $this->run,
            'pageClass' => ViewProcessRun::class,
        ]);
    }

    public function test_a_letter_can_be_previewed_without_sending_anything(): void
    {
        Mail::fake();

        $this->screen()
            ->callAction(
                TestAction::make('previewLetters')->table($this->lettersTask()),
                ['type' => 'relieving', 'supplied' => ['last_date' => '2026-09-30']],
            )
            ->assertFileDownloaded('Relieving-Letter-Departing-Person.pdf');

        Mail::assertNothingSent();
    }

    public function test_the_experience_letter_can_be_previewed_too(): void
    {
        Mail::fake();

        $this->screen()
            ->callAction(
                TestAction::make('previewLetters')->table($this->lettersTask()),
                ['type' => 'experience', 'supplied' => ['last_date' => '2026-09-30']],
            )
            ->assertFileDownloaded('Experience-Letter-Departing-Person.pdf');

        Mail::assertNothingSent();
    }

    public function test_previewing_does_not_write_the_supplied_values_to_the_record(): void
    {
        Mail::fake();

        $this->screen()->callAction(
            TestAction::make('previewLetters')->table($this->lettersTask()),
            ['type' => 'relieving', 'supplied' => ['last_date' => '2026-09-30']],
        );

        // Someone checking how a letter reads should not change the person's
        // exit date as a side effect.
        $this->assertNull($this->employee->refresh()->date_of_exit);
    }

    public function test_sending_emails_both_letters_and_completes_the_step(): void
    {
        Mail::fake();
        $this->unblockLetters();

        $this->screen()->callAction(
            TestAction::make('complete')->table($this->lettersTask()),
            ['supplied' => ['last_date' => '2026-09-30']],
        );

        Mail::assertSent(
            ExitLetterMail::class,
            fn (ExitLetterMail $mail) => $mail->hasTo('leaver@example.com')
                && count($mail->letters) === 2,
        );

        $task = $this->lettersTask();

        $this->assertTrue($task->status->isFinished());
        $this->assertStringContainsString('leaver@example.com', (string) $task->evidence);

        // One outcome row per letter, so a partial failure is auditable later.
        $this->assertCount(2, $task->result);
        $this->assertSame([true, true], array_column($task->result, 'ok'));
    }

    public function test_sending_saves_the_supplied_date_onto_the_record(): void
    {
        Mail::fake();
        $this->unblockLetters();

        $this->screen()->callAction(
            TestAction::make('complete')->table($this->lettersTask()),
            ['supplied' => ['last_date' => '2026-09-30']],
        );

        // Answering the gap once should fix the record, not just this send.
        $this->assertSame('2026-09-30', $this->employee->refresh()->date_of_exit?->toDateString());
    }

    public function test_the_step_stays_open_when_the_letters_cannot_be_built(): void
    {
        Mail::fake();

        $this->unblockLetters();

        // No exit date supplied and none on record, so the letters cannot state
        // a last working day.
        $this->screen()->callAction(
            TestAction::make('complete')->table($this->lettersTask()),
            [],
        );

        Mail::assertNothingSent();

        $this->assertFalse($this->lettersTask()->status->isFinished());
    }
}
