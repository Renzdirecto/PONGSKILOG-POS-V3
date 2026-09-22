<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
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
