<?php

namespace App\Events;

use App\Models\Branch;
use Carbon\CarbonInterface;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

class DisplayOrdersChanged implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array{event_id: string, event_type: string, branch_id: string, occurred_at: string} */
    private array $payload;

    public function __construct(Branch $branch, CarbonInterface $occurredAt)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'display.orders_changed',
            'branch_id' => (string) $branch->getKey(),
            'occurred_at' => $occurredAt->toIso8601String(),
        ];
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('branch.'.$this->payload['branch_id'].'.customer-display');
    }

    public function broadcastAs(): string
    {
        return 'display.orders_changed';
    }

    /** @return array{event_id: string, event_type: string, branch_id: string, occurred_at: string} */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
