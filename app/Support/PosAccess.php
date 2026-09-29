<?php

namespace App\Support;

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class PosAccess
{
    public function authorize(User $user, Branch $branch): User
    {
        $persistedUser = $user->exists ? User::query()->whereKey($user->getKey())->first() : null;
        if ($persistedUser === null || ! $persistedUser->is_active
            || ! $persistedUser->hasPermission('pos.access')
            || ! $persistedUser->hasCashierOperationsRole()
            || ! $persistedUser->hasOperationalBranchAccess($branch)
            || $branch->status !== BranchStatus::Active) {
            throw new AuthorizationException('Only an active assigned cashier or Super Admin may access this branch POS.');
        }

        return $persistedUser;
    }
}
