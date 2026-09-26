<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;

/**
 * Who may receive a Branch's operational signals: the realtime `branch.{id}.pos|kitchen|customer-display` channels
 * and the matching Web Push messages share this one rule. Permission is WHAT (`pos.access`, `kitchen.access`, ...);
 * Branch access is WHERE (an active assignment, or business-wide scope for every Branch). It is evaluated on every
 * subscription or delivery, so a deactivated account, a revoked permission or a removed assignment stops the signal.
 */
class BranchSignalAccess
{
    public static function allows(User $user, Branch $branch, string $permission): bool
    {
        return $user->is_active && $user->hasPermission($permission) && $user->canAccessBranch($branch);
    }
}
