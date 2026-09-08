<?php

namespace App\Enums;

enum ProvisioningMode: string
{
    /** An API call does the work end to end. */
    case Automated = 'automated';

    /** No usable API: HR follows a guided card and records evidence. */
    case Manual = 'manual';

    /** Try the API, fall back to a task card when it refuses. */
    case Semi = 'semi';

    public function label(): string
    {
        return match ($this) {
            self::Automated => 'Automated',
            self::Manual => 'Manual (task card)',
            self::Semi => 'Semi-automatic',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Automated => 'success',
            self::Manual => 'warning',
            self::Semi => 'info',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $m) => [$m->value => $m->label()])
            ->all();
    }
}
