<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active
            && $user->hasBusinessWideScope()
            && $user->hasPermission('settings.manage');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Branch $branch): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->canAccessBranch($branch);
    }

    public function select(User $user, Branch $branch): bool
    {
        return $user->canAccessBranch($branch);
    }
}
