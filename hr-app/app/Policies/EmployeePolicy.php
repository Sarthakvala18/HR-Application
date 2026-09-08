<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canAccessPanel();
    }

    public function view(User $user, Employee $employee): bool
    {
        if ($user->isSuperAdmin() || $user->isHrAdmin() || $user->isFinance()) {
            return true;
        }

        if ($user->isManager()) {
            return $user->managesEmployee($employee) || $user->employee_id === $employee->id;
        }

        return $user->employee_id === $employee->id;
    }

    public function create(User $user): bool
    {
        return $user->canRunProcesses();
    }

    public function update(User $user, Employee $employee): bool
    {
        if ($user->isSuperAdmin() || $user->isHrAdmin()) {
            return true;
        }

        // Employees may maintain their own contact details.
        return $user->employee_id === $employee->id;
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->isSuperAdmin();
    }

    // ------------------------------------------------------- field-level rules

    /** Salary is Super Admin and Finance only, never managers. */
    public function viewSalary(User $user, Employee $employee): bool
    {
        return $user->canSeeFinancials();
    }

    /** Personal email, phone and home address. */
    public function viewContactDetails(User $user, Employee $employee): bool
    {
        return $user->isSuperAdmin()
            || $user->isHrAdmin()
            || $user->isFinance()
            || $user->employee_id === $employee->id;
    }

    /**
     * Birth year is HR-only; the day and month stay visible company-wide so the
     * celebration posts can be generated.
     */
    public function viewBirthYear(User $user, Employee $employee): bool
    {
        return $user->isSuperAdmin()
            || $user->isHrAdmin()
            || $user->employee_id === $employee->id;
    }

    public function viewNotes(User $user, Employee $employee): bool
    {
        return $user->isSuperAdmin() || $user->isHrAdmin();
    }

    // ----------------------------------------------------------- process rules

    public function runOnboarding(User $user, Employee $employee): bool
    {
        return $user->canRunProcesses();
    }

    /** Deliberately narrow, and enforced here rather than in a prompt. */
    public function runOffboarding(User $user, Employee $employee): bool
    {
        return $user->canTriggerOffboarding();
    }

    public function manageAccess(User $user, Employee $employee): bool
    {
        return $user->canRunProcesses();
    }
}
