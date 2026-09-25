<?php

namespace App\Support;

use App\Enums\PushMessageType;
use App\Models\Branch;
use App\Models\PushSubscription;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who receives a push, decided when it is delivered from the current server authority — never from anything stored
 * when a browser subscribed. The rules are the existing ones, not new formulas:
 *
 * - New Kitchen Order: the `branch.{id}.kitchen` channel rule (`BranchSignalAccess` with `kitchen.access`).
 * - Order Ready: the `branch.{id}.pos` channel rule (`pos.access`) — the POS serves ready orders.
 * - Important alert: `AdminNotifier`'s recipient rule, for the one account the Control Center notification was sent to.
 *
 * Business-wide accounts (for example Super Admin) hold every Branch, so they receive a Branch's signals when they
 * hold the permission and enabled notifications on a device, exactly like its realtime channels.
 */
class PushRecipients
{
    /**
     * The Branch a message is about, or null when it has none (alerts) or it no longer exists.
     */
    public function branchFor(PushMessage $message): ?Branch
    {
        return $message->branchId === null ? null : Branch::query()->find($message->branchId);
    }

    /**
     * Subscriptions of every account allowed to receive the message now, optionally limited to a retry subset.
     *
     * @param  list<int>|null  $onlyIds
     * @return Collection<int, PushSubscription>
     */
    public function subscriptionsFor(PushMessage $message, ?Branch $branch, ?array $onlyIds = null): Collection
    {
        $recipientIds = $this->candidates($message, $branch)
            ->filter(fn (User $user): bool => $this->allows($user, $message, $branch))
            ->modelKeys();

        if ($recipientIds === []) {
            return new Collection;
        }

        return PushSubscription::query()
            ->whereIn('user_id', $recipientIds)
            ->when($onlyIds !== null, fn (Builder $query) => $query->whereKey($onlyIds))
            ->orderBy('id')
            ->get();
    }

    public function allows(User $user, PushMessage $message, ?Branch $branch): bool
    {
        return match ($message->type) {
            PushMessageType::KitchenNewOrder => $branch !== null && BranchSignalAccess::allows($user, $branch, 'kitchen.access'),
            PushMessageType::OrderReady => $branch !== null && BranchSignalAccess::allows($user, $branch, 'pos.access'),
            PushMessageType::AdminAlert => (int) $user->getKey() === $message->userId && AdminNotifier::receivesAlerts($user),
        };
    }

    /**
     * Active accounts with at least one subscription that could possibly qualify; `allows()` makes the decision.
     *
     * @return Collection<int, User>
     */
    private function candidates(PushMessage $message, ?Branch $branch): Collection
    {
        if ($message->type === PushMessageType::AdminAlert) {
            return User::query()
                ->whereKey($message->userId)
                ->where('is_active', true)
                ->whereHas('pushSubscriptions')
                ->get();
        }

        if ($branch === null) {
            return new Collection;
        }

        return User::query()
            ->where('is_active', true)
            ->whereHas('pushSubscriptions')
            ->where(fn (Builder $scope) => $scope
                ->whereHas('roles', fn (Builder $roles) => $roles->whereIn('roles.id', Role::query()->businessWide()->select('roles.id')))
                ->orWhereHas('branches', fn (Builder $branches) => $branches
                    ->whereKey($branch->getKey())
                    ->where('user_branch_assignments.is_active', true)))
            ->orderBy('id')
            ->get();
    }
}
