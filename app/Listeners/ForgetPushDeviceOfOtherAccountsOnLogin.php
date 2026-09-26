<?php

namespace App\Listeners;

use App\Models\PushSubscription;
use App\Models\User;
use App\Support\PushDevice;
use Illuminate\Auth\Events\Login;

/**
 * A browser receives the notifications of the account bound to it. When another account signs in there (the previous
 * session expired or was ended without a logout, for example on a shared station), the previous account's
 * subscription on this browser is removed, so the new user never sees another account's notifications. The account
 * signing in keeps its own binding and enables notifications itself. It never blocks or fails the login.
 */
class ForgetPushDeviceOfOtherAccountsOnLogin
{
    public function handle(Login $event): void
    {
        $deviceId = PushDevice::idFrom(request());

        if (! $event->user instanceof User || $deviceId === null) {
            return;
        }

        rescue(fn () => PushSubscription::query()
            ->where('device_hash', PushDevice::hash($deviceId))
            ->where('user_id', '!=', $event->user->getKey())
            ->delete());
    }
}
