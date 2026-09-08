<?php

namespace App\Policies;

use App\Models\AppAccess;
use App\Models\User;

class AppAccessPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canAccessPanel();
    }

    public function view(User $user, AppAccess $access): bool
    {
        if ($user->isSuperAdmin() || $user->isHrAdmin()) {
            return true;
        }

        if ($user->isManager() && $access->employee) {
            return $user->managesEmployee($access->employee);
        }

        return $user->employee_id === $access->employee_id;
    }

    public function create(User $user): bool
    {
        return $user->canRunProcesses();
    }

    public function update(User $user, AppAccess $access): bool
    {
        return $user->canRunProcesses();
    }

    public function grant(User $user, AppAccess $access): bool
    {
        return $user->canRunProcesses();
    }

    public function revoke(User $user, AppAccess $access): bool
    {
        return $user->canRunProcesses();
    }

    public function delete(User $user, AppAccess $access): bool
    {
        return $user->isSuperAdmin();
    }
}
