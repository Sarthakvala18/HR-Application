<?php

namespace App\Services\Process;

use App\Models\Employee;
use App\Models\ProcessRun;
use App\Models\ProcessTask;
use App\Models\User;

/**
 * Shared scaffolding for building a process run.
 *
 * Subclasses declare the task list; this class owns creating the run, numbering
 * the steps, and guaranteeing that a person never has two open runs of the same
 * kind at once.
 */
abstract class RunBuilder
{
    protected int $order = 0;

    /** @var array<int, array<string, mixed>> */
    protected array $tasks = [];

    abstract protected function type(): string;

    /**
     * Adds one step. Keys are stable identifiers used for dependencies, so they
     * must be unique within a run.
     */
    protected function task(
        string $key,
        string $title,
        string $mode = ProcessTask::MODE_MANUAL,
        ?string $description = null,
        ?int $appId = null,
        array $dependsOn = [],
        array $payload = [],
    ): void {
        $this->tasks[] = [
            'key' => $key,
            'title' => $title,
            'mode' => $mode,
            'description_md' => $description,
            'app_id' => $appId,
            'depends_on' => $dependsOn ?: null,
            'payload' => $payload ?: null,
            'sort_order' => $this->order += 10,
        ];
    }

    protected function persist(ProcessRun $run): ProcessRun
    {
        foreach ($this->tasks as $task) {
            $run->tasks()->create($task);
        }

        // Compute initial blocked/pending state from the dependency graph.
        app(ProcessTaskRunner::class)->refreshBlockedStates($run->refresh());

        return $run->refresh();
    }

    protected function createRun(Employee $employee, ?User $initiator, bool $urgent = false): ProcessRun
    {
        return ProcessRun::create([
            'employee_id' => $employee->id,
            'type' => $this->type(),
            'status' => 'in_progress',
            'is_urgent' => $urgent,
            'initiated_by' => $initiator?->id,
            'started_at' => now(),
        ]);
    }

    /** Prevents a second open run of the same kind for the same person. */
    protected function assertNoOpenRun(Employee $employee): void
    {
        $existing = $employee->processRuns()
            ->where('type', $this->type())
            ->open()
            ->exists();

        if ($existing) {
            throw new \RuntimeException(
                "{$employee->full_name} already has an open {$this->type()} run.",
            );
        }
    }
}
