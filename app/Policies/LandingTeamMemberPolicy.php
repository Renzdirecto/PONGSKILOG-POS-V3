<?php

namespace App\Policies;

use App\Models\User;

/**
 * Website content (the landing page's team) is managed by the same accounts that moderate customer reviews: the active
 * Owner and Super Admin holding `settings.manage`. The website belongs to the whole business, never to one Branch.
 */
class LandingTeamMemberPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active
            && $user->roles()->whereIn('roles.name', ['owner', 'super_admin'])->exists()
            && $user->hasPermission('settings.manage');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user): bool
    {
        return $this->viewAny($user);
    }
}
