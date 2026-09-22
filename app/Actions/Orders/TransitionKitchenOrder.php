<?php

namespace App\Actions\Orders;

use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\StoreSessionStatus;
use App\Events\CustomerTrackingChanged;
use App\Events\DisplayOrdersChanged;
use App\Events\KitchenStatusChanged;
use App\Models\Branch;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\StoreSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransitionKitchenOrder
{
    public function execute(User $user, Branch $branch, Order $order, KitchenStatus $target): Order
    {
        return $this->executeWithResult($user, $branch, $order, $target)['order'];
    }

    /**
     * Lock order: OPEN Store Session (shared), Order (exclusive), KitchenTicket (exclusive).
     * A Store Close must take the session's exclusive lock before changing its status
     * or locking orders. Different orders can share the open-session boundary.
     *
     * @return array{order: Order, changed: bool, from: KitchenStatus}
     */
    public function executeWithResult(User $user, Branch $branch, Order $order, KitchenStatus $target): array
    {
        return DB::transaction(function () use ($user, $branch, $order, $target): array {
            $session = StoreSession::query()
                ->whereBelongsTo($branch)
                ->where('status', StoreSessionStatus::Open)
                ->sharedLock()
                ->first();

            if ($session === null) {
                throw ValidationException::withMessages([
                    'status' => 'The store is closed. Kitchen status cannot be changed.',
                ]);
            }

            $lockedOrder = Order::query()
                ->whereKey($order->getKey())
                ->whereBelongsTo($branch)
                ->lockForUpdate()
                ->firstOrFail();

            $ticket = KitchenTicket::query()
                ->where('order_id', $lockedOrder->getKey())
                ->whereBelongsTo($branch)
                ->lockForUpdate()
                ->firstOrFail();

            $this->validateOperationalState($lockedOrder, $ticket, $session);
            $this->authorizeTransition($user, $lockedOrder->kitchen_status, $target);

            if ($lockedOrder->kitchen_status === $target) {
                return [
                    'order' => $lockedOrder->load('kitchenTicket'),
                    'changed' => false,
                    'from' => $target,
                ];
            }

            $from = $lockedOrder->kitchen_status;
            $this->validateTransition($from, $target);

            $changedAt = now();
            $ticket->update(['status' => $target]);
            $lockedOrder->update([
                'kitchen_status' => $target,
                'preparing_at' => $target === KitchenStatus::Kitchen ? null : ($target === KitchenStatus::Preparing && $from === KitchenStatus::Kitchen ? $changedAt : $lockedOrder->preparing_at),
                'ready_at' => in_array($target, [KitchenStatus::Kitchen, KitchenStatus::Preparing], true) ? null : ($target === KitchenStatus::Ready && $from !== KitchenStatus::Done ? $changedAt : $lockedOrder->ready_at),
                'completed_at' => $target === KitchenStatus::Done ? $changedAt : null,
                'version' => $lockedOrder->version + 1,
            ]);

            $lockedOrder->refresh();
            KitchenStatusChanged::dispatch($lockedOrder, $from, $target, $changedAt);
            DisplayOrdersChanged::dispatch($branch, $changedAt);
            CustomerTrackingChanged::dispatch($lockedOrder);

            return [
                'order' => $lockedOrder->load('kitchenTicket'),
                'changed' => true,
                'from' => $from,
            ];
        }, 3);
    }

    private function validateOperationalState(Order $order, KitchenTicket $ticket, StoreSession $session): void
    {
        if ($order->store_session_id !== $session->id
            || $order->committed_at === null
            || $order->commercial_status !== CommercialStatus::Active
            || $order->kitchen_status === KitchenStatus::NotSent
            || $ticket->status !== $order->kitchen_status) {
            throw ValidationException::withMessages([
                'status' => 'The order is not in a valid kitchen state. Refresh the board and try again.',
            ]);
        }
    }

    private function authorizeTransition(User $user, KitchenStatus $from, KitchenStatus $target): void
    {
        if ($user->hasPermission('kitchen.access')) {
            return;
        }

        abort_unless(
            $user->hasPermission('pos.access')
                && $target === KitchenStatus::Done
                && in_array($from, [KitchenStatus::Ready, KitchenStatus::Done], true),
            403,
        );
    }

    private function validateTransition(KitchenStatus $from, KitchenStatus $target): void
    {
        $positions = [
            KitchenStatus::Kitchen->value => 0,
            KitchenStatus::Preparing->value => 1,
            KitchenStatus::Ready->value => 2,
            KitchenStatus::Done->value => 3,
        ];

        $fromPosition = $positions[$from->value] ?? null;
        $targetPosition = $positions[$target->value] ?? null;
        $valid = $fromPosition !== null
            && $targetPosition !== null
            && ($targetPosition > $fromPosition || $targetPosition === $fromPosition - 1);

        if (! $valid) {
            throw ValidationException::withMessages([
                'status' => 'That kitchen status transition is not allowed. Refresh the board and try again.',
            ]);
        }
    }
}
