<?php

namespace App\Actions\Notifications;

use App\Actions\Audit\AuditRecorder;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\PushDevice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RemovePushSubscription
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * Disable notifications for this browser: removes only the account's own subscription, found by this browser's
     * device cookie or its endpoint. Another account's subscription is never touched, and the answer never reveals
     * whether one existed. Audited without subscription material when something was removed.
     *
     * @return int the number of subscriptions removed
     */
    public function execute(User $user, ?string $deviceId, ?string $endpoint): int
    {
        if ($deviceId === null && $endpoint === null) {
            return 0;
        }

        return DB::transaction(function () use ($user, $deviceId, $endpoint): int {
            $removed = $user->pushSubscriptions()
                ->where(fn (Builder $mine) => $mine
                    ->when($deviceId !== null, fn (Builder $query) => $query->orWhere('device_hash', PushDevice::hash((string) $deviceId)))
                    ->when($endpoint !== null, fn (Builder $query) => $query->orWhere('endpoint_hash', PushSubscription::hashEndpoint((string) $endpoint))))
                ->pluck('id');

            if ($removed->isEmpty()) {
                return 0;
            }

            PushSubscription::query()->whereKey($removed->all())->delete();
            $this->audit->record(
                branch: null,
                actor: $user,
                module: 'notifications',
                action: 'notifications.push_disabled',
                auditableType: PushSubscription::class,
                auditableId: (string) $removed->first(),
                metadata: ['subscriptions' => $removed->count()],
            );

            return $removed->count();
        }, 3);
    }
}
