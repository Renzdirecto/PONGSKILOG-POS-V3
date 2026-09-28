<?php

namespace App\Events;

use App\Models\StoreSession;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * Compact Store state invalidation when a Store Session opens (Phase 20), so every Store Operations page of the Branch
 * leaves its Store Closed state at once. Opening balances and the opener stay out of Branch realtime.
 */
class StoreOpened implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array{event_id: string, event_type: string, branch_id: string, store_session_id: string, opened_at: string, state: string, occurred_at: string} */
    private array $payload;

    public function __construct(StoreSession $session)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'store.opened',
            'branch_id' => (string) $session->branch_id,
            'store_session_id' => $session->id,
            'opened_at' => $session->opened_at->toIso8601String(),
            'state' => 'open',
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $branchId = $this->payload['branch_id'];

        return [
            new PrivateChannel('branch.'.$branchId.'.pos'),
            new PrivateChannel('branch.'.$branchId.'.kitchen'),
            new PrivateChannel('branch.'.$branchId.'.store-session'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'store.opened';
    }

    /** @return array{event_id: string, event_type: string, branch_id: string, store_session_id: string, opened_at: string, state: string, occurred_at: string} */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
