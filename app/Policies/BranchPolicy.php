<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

/**
 * Branch Settings. Business-wide `settings.manage` (Owner, Super Admin, business-wide Custom Roles) manages every
 * Branch: create, identity (code, name, status) and details. Branch-scoped `settings.manage` (a Branch Custom Role)
 * manages only its own assigned Branches' local settings: contact details, Customer QR and receipt settings. It never
 * creates a Branch, changes a Branch's code, name or status, or sees another Branch.
 */
class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active
            && $user->hasPermission('settings.manage')
            && ($user->hasBusinessWideScope() || $user->branches()->wherePivot('is_active', true)->exists());
    }

    public function create(User $user): bool
    {
        return $this->managesEveryBranch($user);
    }

    /** Branch-local settings (contact details, Customer QR, receipt) of one Branch the account may manage. */
    public function update(User $user, Branch $branch): bool
    {
        return $user->is_active && $user->hasPermission('settings.manage') && $user->canAccessBranch($branch);
    }

    /** A Branch's identity and status (code, name, Active/Inactive) are business-wide administration. */
    public function updateIdentity(User $user, Branch $branch): bool
    {
        return $this->managesEveryBranch($user);
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->canAccessBranch($branch);
    }

    public function select(User $user, Branch $branch): bool
    {
        return $user->canAccessBranch($branch);
    }

    private function managesEveryBranch(User $user): bool
    {
        return $user->is_active && $user->hasBusinessWideScope() && $user->hasPermission('settings.manage');
    }
}
