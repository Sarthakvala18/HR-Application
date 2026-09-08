<?php

namespace App\Policies;

use App\Models\FormSubmission;
use App\Models\User;

class FormSubmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canRunProcesses() || $user->canSeeFinancials();
    }

    public function view(User $user, FormSubmission $submission): bool
    {
        // Bank submissions carry account numbers: Finance and Super Admin only.
        if ($submission->form_key === FormSubmission::FORM_BANK) {
            return $user->canSeeFinancials();
        }

        return $user->canRunProcesses();
    }

    /** Confirming an identity match writes to a real person's record. */
    public function review(User $user, FormSubmission $submission): bool
    {
        if ($submission->form_key === FormSubmission::FORM_BANK) {
            return $user->canSeeFinancials();
        }

        return $user->canRunProcesses();
    }

    public function delete(User $user, FormSubmission $submission): bool
    {
        return $user->isSuperAdmin();
    }
}
