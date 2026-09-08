<?php

namespace App\Enums;

enum EmployeeStatus: string
{
    case PreOnboarding = 'pre_onboarding';
    case Active = 'active';
    case OnNotice = 'on_notice';
    case Offboarding = 'offboarding';
    case Exited = 'exited';

    public function label(): string
    {
        return match ($this) {
            self::PreOnboarding => 'Pre-onboarding',
            self::Active => 'Active',
            self::OnNotice => 'On notice',
            self::Offboarding => 'Offboarding',
            self::Exited => 'Exited',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PreOnboarding => 'info',
            self::Active => 'success',
            self::OnNotice => 'warning',
            self::Offboarding => 'warning',
            self::Exited => 'gray',
        };
    }

    /** Should this person still hold any app access? */
    public function shouldHaveAccess(): bool
    {
        return in_array($this, [self::Active, self::OnNotice, self::Offboarding], true);
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s) => [$s->value => $s->label()])
            ->all();
    }
}
