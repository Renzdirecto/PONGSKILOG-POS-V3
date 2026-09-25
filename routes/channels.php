<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/** A user's own private channel (notification signals): only the same, still-active account. */
Broadcast::channel('App.Models.User.{id}', function (User $user, $id): bool {
    return $user->is_active && (int) $user->id === (int) $id;
});

foreach ([
    'pos' => 'pos.access',
    'kitchen' => 'kitchen.access',
    'customer-display' => 'customer_display.launch',
] as $channel => $permission) {
    Broadcast::channel('branch.{branch}.'.$channel, function (User $user, Branch $branch) use ($permission): bool {
        return $user->is_active && $user->hasPermission($permission) && $user->canAccessBranch($branch);
    });
}

Broadcast::channel('branch.{branch}.inventory', function (User $user, Branch $branch): bool {
    return $user->is_active
        && $user->canAccessBranch($branch)
        && ($user->hasPermission('pos.access') || $user->hasPermission('inventory.manage') || $user->hasPermission('products.manage'));
});

Broadcast::channel('branch.{branch}.store-session', function (User $user, Branch $branch): bool {
    return $user->is_active
        && $user->canAccessBranch($branch)
        && $user->hasPermission('store_expenses.manage')
        && $user->hasCashierOperationsRole()
        && $user->hasOperationalBranchAccess($branch);
});

/** Business-wide report invalidation signals for the Owner/Super Admin Dashboard and Reports. */
Broadcast::channel('reports', function (User $user): bool {
    return $user->is_active && $user->hasPermission('reports.view') && $user->hasBusinessWideScope();
});

/** Branch-scoped report invalidation for accounts with Reports access at that Branch (custom Reports for Branch staff). */
Broadcast::channel('branch.{branch}.reports', function (User $user, Branch $branch): bool {
    return $user->is_active && $user->hasPermission('reports.view') && $user->canAccessBranch($branch);
});

/** Open Access Control pages: Super Admin access control only. */
Broadcast::channel('access-control', function (User $user): bool {
    return $user->is_active && $user->hasPermission('access_control.manage');
});

/** Business-wide Staff pages: Super Admin access control, or business-wide Staff management (Owner, business-wide Custom Roles). */
Broadcast::channel('staff', function (User $user): bool {
    return $user->is_active
        && ($user->hasPermission('access_control.manage') || ($user->hasPermission('staff.manage') && $user->hasBusinessWideScope()));
});

/** Branch-scoped Staff pages: Staff management at an assigned Branch; signals about other Branches never reach it. */
Broadcast::channel('branch.{branch}.staff', function (User $user, Branch $branch): bool {
    return $user->is_active && $user->hasPermission('staff.manage') && $user->canAccessBranch($branch);
});

Broadcast::channel('audit-trail', function (User $user): bool {
    return $user->is_active && $user->hasPermission('audit.view');
});
