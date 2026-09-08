<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcessRun extends Model
{
    use HasFactory;

    public const TYPE_ONBOARDING = 'onboarding';

    public const TYPE_OFFBOARDING = 'offboarding';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_urgent' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProcessTask::class)->orderBy('sort_order');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['draft', 'in_progress', 'blocked']);
    }

    public function isOnboarding(): bool
    {
        return $this->type === self::TYPE_ONBOARDING;
    }

    /** Required tasks still outstanding. */
    public function outstandingTasks()
    {
        return $this->tasks->reject(fn (ProcessTask $t) => $t->status->isFinished());
    }

    public function progressPercent(): int
    {
        $total = $this->tasks->count();

        if ($total === 0) {
            return 0;
        }

        $done = $this->tasks->filter(fn (ProcessTask $t) => $t->status->isFinished())->count();

        return (int) round($done / $total * 100);
    }

    /** Manual cards HR still owes, surfaced on the dashboard. */
    public function blockedOnManual()
    {
        return $this->tasks->filter(
            fn (ProcessTask $t) => $t->mode === 'manual' && ! $t->status->isFinished(),
        );
    }

    public function recomputeStatus(): void
    {
        if ($this->tasks->isEmpty()) {
            return;
        }

        if ($this->tasks->every(fn (ProcessTask $t) => $t->status->isFinished())) {
            $this->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return;
        }

        $hasFailure = $this->tasks->contains(fn (ProcessTask $t) => $t->status === TaskStatus::Failed);

        $this->update(['status' => $hasFailure ? 'blocked' : 'in_progress']);
    }
}
