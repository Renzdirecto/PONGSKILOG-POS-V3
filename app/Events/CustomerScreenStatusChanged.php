<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * A customer screen of this Branch changed its mode or pairing without its POS station asking (the screen's own
 * header controls, its staff reset, or the Menu closing after an order confirmation). Sent on the Branch POS channel
 * as a compact invalidation — no screen, station, order or mode data — so each POS header refetches its own station's
 * screen status and the two controls never disagree.
 */
class CustomerScreenStatusChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array{event_id: string, event_type: string, branch_id: string, occurred_at: string} */
    private array $payload;

    public function __construct(string $branchId)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'customer_screen.status_changed',
            'branch_id' => $branchId,
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('branch.'.$this->payload['branch_id'].'.pos')];
    }

    public function broadcastAs(): string
    {
        return 'customer_screen.status_changed';
    }

    /** @return array{event_id: string, event_type: string, branch_id: string, occurred_at: string} */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
