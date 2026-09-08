<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case HrAdmin = 'hr_admin';
    case Finance = 'finance';
    case Manager = 'manager';
    case Employee = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::HrAdmin => 'HR Admin',
            self::Finance => 'Finance',
            self::Manager => 'Manager',
            self::Employee => 'Employee',
        };
    }

    /** Roles allowed to open the admin panel at all. */
    public function canAccessPanel(): bool
    {
        return $this !== self::Employee;
    }

    /** Who may see salary and bank details. */
    public function canSeeFinancials(): bool
    {
        return in_array($this, [self::SuperAdmin, self::Finance], true);
    }

    /** Who may run onboarding and offboarding. */
    public function canRunProcesses(): bool
    {
        return in_array($this, [self::SuperAdmin, self::HrAdmin], true);
    }

    /** Who may trigger an offboarding (enforced here, not in a prompt). */
    public function canTriggerOffboarding(): bool
    {
        return in_array($this, [self::SuperAdmin, self::HrAdmin], true);
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $r) => [$r->value => $r->label()])
            ->all();
    }
}
