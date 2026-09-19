<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

foreach (['pos' => 'pos.access', 'kitchen' => 'kitchen.access'] as $channel => $permission) {
    Broadcast::channel('branch.{branch}.'.$channel, function (User $user, Branch $branch) use ($permission): bool {
        return $user->is_active && $user->hasPermission($permission) && $user->canAccessBranch($branch);
    });
}
