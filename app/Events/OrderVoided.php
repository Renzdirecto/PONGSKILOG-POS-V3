<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

class OrderVoided implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array<string, string|int> */
    private array $payload;

    /**
     * Create a new event instance.
     */
    public function __construct(Order $order)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'order.voided',
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'version' => $order->version,
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->payload['branch_id'].'.pos'),
            new PrivateChannel('branch.'.$this->payload['branch_id'].'.kitchen'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.voided';
    }

    /** @return array<string, string|int> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
