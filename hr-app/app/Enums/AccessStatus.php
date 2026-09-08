<?php

namespace App\Enums;

enum AccessStatus: string
{
    case None = 'none';
    case Requested = 'requested';
    case PendingManual = 'pending_manual';
    case Active = 'active';
    case RevokePending = 'revoke_pending';
    case Revoked = 'revoked';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No access',
            self::Requested => 'Requested',
            self::PendingManual => 'Awaiting manual step',
            self::Active => 'Active',
            self::RevokePending => 'Revoke pending',
            self::Revoked => 'Revoked',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::None, self::Revoked => 'gray',
            self::Requested, self::RevokePending => 'info',
            self::PendingManual => 'warning',
            self::Active => 'success',
            self::Failed => 'danger',
        };
    }

    /** Does this state mean the person can currently get in? */
    public function isLive(): bool
    {
        return in_array($this, [self::Active, self::RevokePending], true);
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s) => [$s->value => $s->label()])
            ->all();
    }
}
