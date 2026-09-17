<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function view(User $user, Branch $branch): bool
    {
        return $user->canAccessBranch($branch);
    }

    public function select(User $user, Branch $branch): bool
    {
        return $user->canAccessBranch($branch);
    }
}
