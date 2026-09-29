<?php

namespace App\Http\Controllers;

use App\Actions\Notifications\RemovePushSubscription;
use App\Actions\Notifications\SavePushSubscription;
use App\Http\Requests\DestroyPushSubscriptionRequest;
use App\Http\Requests\StorePushSubscriptionRequest;
use App\Models\User;
use App\Support\PushDevice;
use App\Support\PushNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * This browser's Web Push subscription for the signed-in account. Responses never echo the endpoint or keys; the
 * account can only see and change its own current-browser subscription.
 */
class PushSubscriptionController extends Controller
{
    /** Whether push works on this server, its public VAPID key, and whether this browser is enabled for this account. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active, 403);
        $deviceId = PushDevice::idFrom($request);

        return response()->json([
            'available' => PushNotifications::enabled(),
            'public_key' => PushNotifications::publicKey(),
            'enabled' => $deviceId !== null && $user->pushSubscriptions()
                ->where('device_hash', PushDevice::hash($deviceId))
                ->exists(),
        ]);
    }

    /** Enable (or re-sync) this browser; the device cookie lets a later logout unbind it server-side. */
    public function store(StorePushSubscriptionRequest $request, SavePushSubscription $save): JsonResponse
    {
        if (! PushNotifications::enabled()) {
            return response()->json(['message' => 'Notifications are not available on this server yet.'], 503);
        }

        /** @var User $user */
        $user = $request->user();
        $deviceId = PushDevice::idFrom($request) ?? PushDevice::newId();
        $save->execute($user, $deviceId, $request->subscription());

        return response()->json(['enabled' => true])->withCookie(PushDevice::cookie($deviceId));
    }

    /** Disable notifications for this browser: only the signed-in account's own subscription is removed. */
    public function destroy(DestroyPushSubscriptionRequest $request, RemovePushSubscription $remove): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $endpoint = $request->validated('endpoint');
        $remove->execute($user, PushDevice::idFrom($request), is_string($endpoint) ? $endpoint : null);

        return response()->json(['enabled' => false]);
    }
}
