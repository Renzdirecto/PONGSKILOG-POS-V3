<?php

namespace App\Events;

use App\Models\StoreSession;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/** Compact Store state invalidation; balances, variances, notes and Audit detail stay out of branch realtime. */
class StoreClosed implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array{event_id: string, event_type: string, branch_id: string, store_session_id: string, closed_at: string|null, closed_by_user_id: int|null, state: string, occurred_at: string} */
    private array $payload;

    /**
     * Create a new event instance.
     */
    public function __construct(StoreSession $session)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'store.closed',
            'branch_id' => (string) $session->branch_id,
            'store_session_id' => $session->id,
            'closed_at' => $session->closed_at?->toIso8601String(),
            'closed_by_user_id' => $session->closed_by_user_id,
            'state' => 'closed',
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
        $branchId = $this->payload['branch_id'];

        return [
            new PrivateChannel('branch.'.$branchId.'.pos'),
            new PrivateChannel('branch.'.$branchId.'.kitchen'),
            new PrivateChannel('branch.'.$branchId.'.store-session'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'store.closed';
    }

    /** @return array{event_id: string, event_type: string, branch_id: string, store_session_id: string, closed_at: string|null, closed_by_user_id: int|null, state: string, occurred_at: string} */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
