<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * A Take Out order's Buzz state changed (the customer enabled or lost notifications, or a cashier buzzed): the POS
 * Ready list refetches, so Buzz Customer appears, disappears or shows its cooldown on every cashier device of the
 * Branch. Sent on the existing `branch.{id}.pos` channel with ids and time only.
 */
class PickupNotifyChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array{event_id: string, event_type: string, branch_id: string, order_id: string, occurred_at: string} */
    private array $payload;

    public function __construct(string $branchId, string $orderId)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'pickup.notify_changed',
            'branch_id' => $branchId,
            'order_id' => $orderId,
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('branch.'.$this->payload['branch_id'].'.pos');
    }

    public function broadcastAs(): string
    {
        return 'pickup.notify_changed';
    }

    /** @return array{event_id: string, event_type: string, branch_id: string, order_id: string, occurred_at: string} */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
