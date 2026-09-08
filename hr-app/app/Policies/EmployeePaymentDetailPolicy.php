<?php

namespace App\Policies;

use App\Models\EmployeePaymentDetail;
use App\Models\User;

class EmployeePaymentDetailPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canSeeFinancials();
    }

    /** Seeing that a record exists, masked to the last four digits. */
    public function view(User $user, EmployeePaymentDetail $detail): bool
    {
        if ($user->canSeeFinancials()) {
            return true;
        }

        // People can see their own payout details.
        return $user->employee_id === $detail->employee_id;
    }

    /**
     * Decrypting the full account number. Separate from `view` on purpose:
     * masked display is routine, revealing is an event worth logging.
     */
    public function reveal(User $user, EmployeePaymentDetail $detail): bool
    {
        if ($user->canSeeFinancials()) {
            return true;
        }

        return $user->employee_id === $detail->employee_id;
    }

    public function create(User $user): bool
    {
        return $user->canSeeFinancials() || $user->isHrAdmin();
    }

    public function update(User $user, EmployeePaymentDetail $detail): bool
    {
        return $user->canSeeFinancials();
    }

    public function verify(User $user, EmployeePaymentDetail $detail): bool
    {
        return $user->canSeeFinancials();
    }

    public function delete(User $user, EmployeePaymentDetail $detail): bool
    {
        return $user->isSuperAdmin();
    }

    /** Bulk payout exports stay with the Super Admin, watermarked and logged. */
    public function export(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
