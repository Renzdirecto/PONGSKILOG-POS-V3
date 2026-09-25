<?php

namespace App\Listeners;

use App\Models\PushSubscription;
use App\Models\User;
use App\Support\PushDevice;
use Illuminate\Auth\Events\Logout;

/**
 * An explicit logout unbinds this browser's push subscription on the server, whichever control triggered the logout
 * and even when the browser's own Push API cleanup failed. It never blocks or fails the logout.
 */
class ForgetPushDeviceOnLogout
{
    public function handle(Logout $event): void
    {
        $deviceId = PushDevice::idFrom(request());

        if (! $event->user instanceof User || $deviceId === null) {
            return;
        }

        rescue(fn () => PushSubscription::query()
            ->where('user_id', $event->user->getKey())
            ->where('device_hash', PushDevice::hash($deviceId))
            ->delete());
    }
}
