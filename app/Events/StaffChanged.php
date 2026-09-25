<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * Invalidation-only signal for open Staff pages: a Staff account (or a role label it shows) changed. It goes to the
 * business-wide `staff` channel and to the `branch.{id}.staff` channel of every Branch the account was or is assigned
 * to, so a Branch-scoped Staff manager hears only about its own Branches. It never carries Staff data; clients reload
 * the list they are authorized to see.
 */
class StaffChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array{event_id: string, event_type: string, occurred_at: string} */
    private array $payload;

    /** @param list<string> $branchIds */
    public function __construct(private array $branchIds)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'staff.changed',
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('staff'),
            ...array_map(fn (string $branchId): PrivateChannel => new PrivateChannel('branch.'.$branchId.'.staff'), $this->branchIds),
        ];
    }

    public function broadcastAs(): string
    {
        return 'staff.changed';
    }

    /** @return array{event_id: string, event_type: string, occurred_at: string} */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
