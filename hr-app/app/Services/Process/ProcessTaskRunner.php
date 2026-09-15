<?php

namespace App\Services\Process;

use App\Enums\AccessStatus;
use App\Enums\EmployeeStatus;
use App\Enums\TaskStatus;
use App\Models\AppAccess;
use App\Models\ProcessRun;
use App\Models\ProcessTask;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Zoho\LetterService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Advances a run: decides what is startable, enforces the rules a paper
 * checklist cannot, and keeps the access matrix in step with what the tasks say
 * actually happened.
 */
class ProcessTaskRunner
{
    /** The offboarding step that dispatches the exit letters. */
    public const LETTERS_KEY = 'letters';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Dispatches both exit letters and records the outcome on the task.
     *
     * Throws unless every letter went out, so a partial or failed send leaves
     * the step outstanding rather than silently completed.
     */
    private function sendExitLetters(ProcessTask $task, ?string $evidence): string
    {
        $employee = $task->run->employee;

        if ($employee === null) {
            throw new RuntimeException('This run has no employee attached.');
        }

        // Values the sender supplied for gaps that have no column of their own,
        // such as "Reports to" or the HR signatory name.
        $context = $task->payload['supplied'] ?? [];

        // Emailed rather than dispatched through Zoho Sign: the licence allows
        // creating documents but not sending them, so the app fills the same
        // templates itself and attaches them.
        $results = app(LetterService::class)->emailExitLetters(
            $employee->refresh(),
            context: $context,
        );

        $task->update(['result' => $results]);

        $failed = array_filter($results, fn (array $r) => ! $r['ok']);

        if ($failed !== []) {
            $reasons = implode(' | ', array_map(
                fn (array $r) => $r['template'].': '.$r['error'],
                $failed,
            ));

            $sent = array_filter($results, fn (array $r) => $r['ok']);

            throw new RuntimeException(
                ($sent === []
                    ? 'No letters were sent. '
                    : count($sent).' of '.count($results).' letters were sent, the rest failed. ')
                .$reasons,
            );
        }

        $summary = implode(', ', array_column($results, 'template'));

        return trim(($evidence ? $evidence.' — ' : '')
            .'Emailed to '.($employee->personal_email ?: $employee->work_email).': '.$summary);
    }

    /**
     * Recomputes which tasks are blocked by unfinished dependencies. Called
     * after building a run and after every completion.
     */
    public function refreshBlockedStates(ProcessRun $run): void
    {
        $byKey = $run->tasks()->get()->keyBy('key');

        foreach ($byKey as $task) {
            if ($task->status->isFinished() || $task->status === TaskStatus::Failed) {
                continue;
            }

            $blocked = collect($task->depends_on ?? [])
                ->contains(fn (string $key) => ! ($byKey[$key]?->status->isFinished() ?? true));

            $target = $blocked ? TaskStatus::Blocked : TaskStatus::Pending;

            if ($task->status !== $target) {
                $task->update(['status' => $target]);
            }
        }
    }

    /**
     * Marks a task done.
     *
     * Manual steps must carry evidence: a step someone can tick without
     * recording what they did is a checkbox that lies.
     */
    public function complete(ProcessTask $task, ?User $user = null, ?string $evidence = null): ProcessTask
    {
        if ($task->status === TaskStatus::Blocked) {
            throw new RuntimeException('This step is still waiting on an earlier one.');
        }

        if ($task->requiresEvidence() && blank($evidence) && blank($task->evidence)) {
            throw new RuntimeException('Manual steps need evidence of what was done.');
        }

        // The letters step claims documents were sent, so it must actually send
        // them. Marking it done without a successful send would be a checkbox
        // that lies, which is the failure mode this whole pipeline exists to
        // remove.
        if ($task->key === self::LETTERS_KEY && blank($task->result)) {
            $evidence = $this->sendExitLetters($task, $evidence);
        }

        return DB::transaction(function () use ($task, $user, $evidence) {
            $task->update([
                'status' => TaskStatus::Done,
                'evidence' => $evidence ?: $task->evidence,
                'completed_at' => now(),
                'completed_by' => $user?->id,
                'error' => null,
            ]);

            $this->applySideEffects($task, $user, $evidence);

            $run = $task->run->refresh();
            $this->refreshBlockedStates($run);
            $this->recomputeRun($run);

            return $task->refresh();
        });
    }

    public function skip(ProcessTask $task, ?User $user = null, ?string $reason = null): ProcessTask
    {
        $task->update([
            'status' => TaskStatus::Skipped,
            'evidence' => $reason ?: $task->evidence,
            'completed_at' => now(),
            'completed_by' => $user?->id,
        ]);

        $run = $task->run->refresh();
        $this->refreshBlockedStates($run);
        $this->recomputeRun($run);

        return $task->refresh();
    }

    public function fail(ProcessTask $task, string $error): ProcessTask
    {
        $task->update(['status' => TaskStatus::Failed, 'error' => $error]);

        $task->run->refresh()->recomputeStatus();

        return $task->refresh();
    }

    /**
     * Completing a provisioning or revocation step is what makes the access
     * matrix true, so the two are updated together rather than relying on
     * someone remembering to do both.
     */
    private function applySideEffects(ProcessTask $task, ?User $user, ?string $evidence): void
    {
        if ($task->app_id === null) {
            return;
        }

        $employeeId = $task->run->employee_id;
        $isRevocation = str_starts_with($task->key, OffboardingRunBuilder::REVOKE_PREFIX);

        $access = AppAccess::firstOrNew([
            'employee_id' => $employeeId,
            'app_id' => $task->app_id,
        ]);

        if ($isRevocation) {
            $access->fill([
                'status' => AccessStatus::Revoked,
                'revoked_at' => now(),
                'revoked_by' => $user?->id,
            ]);
            $access->save();

            $this->audit->log(AuditLogger::ACCESS_REVOKED, $access, [
                'app_id' => $task->app_id,
                'via' => 'offboarding_run',
            ]);

            return;
        }

        $access->fill([
            'status' => AccessStatus::Active,
            'granted_at' => now(),
            'granted_by' => $user?->id,
            'license_tier' => $task->payload['license_tier'] ?? $access->license_tier,
            'scopes' => $task->payload['scopes'] ?? $access->scopes,
            // Manual steps record the created account id as their evidence.
            'external_id' => $evidence ?: $access->external_id,
        ]);
        $access->save();

        $this->audit->log(AuditLogger::ACCESS_GRANTED, $access, [
            'app_id' => $task->app_id,
            'via' => 'onboarding_run',
        ]);
    }

    /**
     * Closes the run when everything is finished, and moves the person to the
     * status the completed run implies.
     */
    private function recomputeRun(ProcessRun $run): void
    {
        $run->load('tasks');
        $run->recomputeStatus();

        if ($run->refresh()->status !== 'completed') {
            return;
        }

        $employee = $run->employee;

        if ($employee === null) {
            return;
        }

        if ($run->isOnboarding()) {
            $employee->update(['status' => EmployeeStatus::Active]);

            return;
        }

        $employee->update([
            'status' => EmployeeStatus::Exited,
            'date_of_exit' => $employee->date_of_exit ?? now()->toDateString(),
        ]);
    }

    /**
     * Satisfies the signature gate. Called by the Zoho Sign webhook once the
     * document is signed, and available to HR as a manual override.
     */
    public function satisfySignatureGate(ProcessRun $run, ?User $user = null): void
    {
        $gate = $run->tasks()->where('key', OnboardingRunBuilder::GATE_KEY)->first();

        if ($gate === null || $gate->status->isFinished()) {
            return;
        }

        $contract = $run->tasks()->where('key', 'contract')->first();

        if ($contract !== null && ! $contract->status->isFinished()) {
            $this->complete($contract, $user, 'Signed document received.');
        }

        $this->complete($gate->refresh(), $user, 'Signature confirmed.');
    }
}
