<?php

namespace App\Actions\AccessControl;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class AccessControlActor
{
    /**
     * Re-reads the acting account inside the write transaction: only a still-active holder of Super Admin access
     * control may change Role baselines or custom access, even if the route check passed moments earlier.
     */
    public static function resolve(User $actor): User
    {
        $fresh = User::query()->whereKey($actor->getKey())->first();

        if ($fresh === null || ! $fresh->is_active || ! $fresh->hasPermission('access_control.manage')) {
            throw new AuthorizationException('Only an active Super Admin may change access.');
        }

        return $fresh;
    }
}
