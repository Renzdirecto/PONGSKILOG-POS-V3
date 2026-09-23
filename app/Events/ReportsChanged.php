<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * Invalidation-only signal for the Owner/Super Admin Dashboard and Reports: something that changes business figures
 * happened in a Branch. It never carries order, customer, item or money data — clients refetch the authorized report.
 */
class ReportsChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array{event_id: string, event_type: string, branch_id: string, reason: string, occurred_at: string} */
    private array $payload;

    public function __construct(string $branchId, string $reason)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'reports.changed',
            'branch_id' => $branchId,
            'reason' => $reason,
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('reports');
    }

    public function broadcastAs(): string
    {
        return 'reports.changed';
    }

    /** @return array{event_id: string, event_type: string, branch_id: string, reason: string, occurred_at: string} */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
