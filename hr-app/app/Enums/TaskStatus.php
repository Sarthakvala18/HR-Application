<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Blocked = 'blocked';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Blocked => 'Blocked',
            self::InProgress => 'In progress',
            self::Done => 'Done',
            self::Skipped => 'Skipped',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Blocked => 'warning',
            self::InProgress => 'info',
            self::Done, self::Skipped => 'success',
            self::Failed => 'danger',
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Done, self::Skipped], true);
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s) => [$s->value => $s->label()])
            ->all();
    }
}
