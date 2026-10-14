<?php

namespace App\Policies;

use App\Models\User;

/**
 * Shippy is website content: its switch and usage figures belong to the same accounts that manage the landing team
 * and moderate reviews, the active Owner and Super Admin holding `settings.manage`.
 */
class ShippySettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active
            && $user->roles()->whereIn('roles.name', ['owner', 'super_admin'])->exists()
            && $user->hasPermission('settings.manage');
    }

    public function update(User $user): bool
    {
        return $this->viewAny($user);
    }
}
