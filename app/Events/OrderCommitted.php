<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

class OrderCommitted implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array<string, mixed> */
    private array $payload;

    public function __construct(Order $order)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(), 'event_type' => 'order.committed',
            'branch_id' => $order->branch_id, 'entity_id' => $order->id,
            'order_id' => $order->id, 'order_number' => $order->order_number,
            'occurred_at' => $order->committed_at?->toIso8601String(), 'version' => $order->version,
            'payment_status' => $order->payment_status->value, 'payment_term' => $order->payment_term?->value, 'kitchen_status' => $order->kitchen_status->value,
        ];
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('branch.'.$this->payload['branch_id'].'.pos')];
    }

    public function broadcastAs(): string
    {
        return 'order.committed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
