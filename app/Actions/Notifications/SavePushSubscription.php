<?php

namespace App\Actions\Notifications;

use App\Actions\Audit\AuditRecorder;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\PushDevice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class SavePushSubscription
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * Enable (or re-sync) Web Push for one browser of an active account. One row per endpoint, guaranteed by the unique
     * `endpoint_hash`: a repeated Enable, a second tab or a key rotation updates it (a concurrent insert of the same
     * endpoint is absorbed by `updateOrCreate`'s savepoint), and a browser previously enabled for another account moves
     * to this one. A browser holds one subscription per app, so any other endpoint of this device is stale and removed.
     * Audited without endpoint, keys or device id, and only when a browser is newly bound to this account.
     *
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}, content_encoding?: string}  $subscription
     */
    public function execute(User $user, string $deviceId, array $subscription): PushSubscription
    {
        return DB::transaction(function () use ($user, $deviceId, $subscription): PushSubscription {
            $user = User::query()->whereKey($user->getKey())->first();
            if ($user === null || ! $user->is_active) {
                throw new AuthorizationException('Only an active account may enable notifications.');
            }
            $deviceHash = PushDevice::hash($deviceId);

            $saved = PushSubscription::query()->updateOrCreate(
                ['endpoint_hash' => PushSubscription::hashEndpoint($subscription['endpoint'])],
                [
                    'user_id' => $user->getKey(),
                    'device_hash' => $deviceHash,
                    'endpoint' => $subscription['endpoint'],
                    'public_key' => $subscription['keys']['p256dh'],
                    'auth_token' => $subscription['keys']['auth'],
                    'content_encoding' => $subscription['content_encoding'] ?? 'aes128gcm',
                ],
            );

            PushSubscription::query()
                ->where('device_hash', $deviceHash)
                ->whereKeyNot($saved->getKey())
                ->delete();

            if ($saved->wasRecentlyCreated || $saved->wasChanged('user_id')) {
                $this->audit->record(
                    branch: null,
                    actor: $user,
                    module: 'notifications',
                    action: 'notifications.push_enabled',
                    auditableType: PushSubscription::class,
                    auditableId: (string) $saved->getKey(),
                    metadata: ['moved_from_another_account' => ! $saved->wasRecentlyCreated],
                );
            }

            return $saved;
        }, 3);
    }
}
