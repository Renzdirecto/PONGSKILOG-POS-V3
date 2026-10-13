<?php

namespace App\Policies;

use App\Models\CustomerReview;
use App\Models\User;

class CustomerReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasRole('owner') && $user->hasPermission('settings.manage');
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
