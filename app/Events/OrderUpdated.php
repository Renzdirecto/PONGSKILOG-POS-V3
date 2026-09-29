<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

class OrderUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array<string, mixed> */
    private array $payload;

    /** @param list<string> $changes */
    public function __construct(Order $order, array $changes)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(), 'event_type' => 'order.updated',
            'branch_id' => $order->branch_id, 'entity_id' => $order->id,
            'order_id' => $order->id, 'version' => $order->version,
            'changes' => $changes, 'occurred_at' => now()->toIso8601String(),
        ];
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('branch.'.$this->payload['branch_id'].'.pos')];
    }

    public function broadcastAs(): string
    {
        return 'order.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
