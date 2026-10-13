<?php

namespace App\Policies;

use App\Models\CustomerReview;
use App\Models\User;

/**
 * Customer reviews are moderated by the active Owner and Super Admin holding `settings.manage`, each only at Branches
 * the account can access. Private feedback never has an approval path.
 */
class CustomerReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active
            && $user->roles()->whereIn('roles.name', ['owner', 'super_admin'])->exists()
            && $user->hasPermission('settings.manage');
    }

    public function view(User $user, CustomerReview $review): bool
    {
        return $this->viewAny($user) && $user->canAccessBranch($review->branch);
    }

    public function update(User $user, CustomerReview $review): bool
    {
        return $this->view($user, $review) && $review->visibility === 'public' && $review->public_consent;
    }

    public function delete(User $user, CustomerReview $review): bool
    {
        return $this->view($user, $review);
    }
}
