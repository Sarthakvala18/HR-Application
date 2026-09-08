<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessTask extends Model
{
    use HasFactory;

    public const MODE_AUTOMATIC = 'automatic';

    public const MODE_MANUAL = 'manual';

    public const MODE_APPROVAL = 'approval';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'depends_on' => 'array',
            'payload' => 'array',
            'result' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProcessRun::class, 'process_run_id');
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            TaskStatus::Done->value,
            TaskStatus::Skipped->value,
        ]);
    }

    /** Every task this one waits on has finished. */
    public function dependenciesSatisfied(): bool
    {
        $keys = $this->depends_on ?? [];

        if ($keys === []) {
            return true;
        }

        return ! $this->run
            ->tasks()
            ->whereIn('key', $keys)
            ->outstanding()
            ->exists();
    }

    /**
     * Manual steps must carry proof of completion, otherwise "done" is just
     * someone clicking a button.
     */
    public function requiresEvidence(): bool
    {
        return $this->mode === self::MODE_MANUAL;
    }

    public function markDone(?User $user = null, ?string $evidence = null): void
    {
        $this->update([
            'status' => TaskStatus::Done,
            'evidence' => $evidence ?? $this->evidence,
            'completed_at' => now(),
            'completed_by' => $user?->id,
        ]);

        $this->run->refresh()->recomputeStatus();
    }
}
