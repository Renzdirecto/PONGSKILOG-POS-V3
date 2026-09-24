<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * Branch Ingredient stock changed (sale, edit, void, wastage, count, opening balance or Pamamalengke restock), so
 * Recipe-based Product availability may have changed. An invalidation signal only: POS clients refetch the
 * authoritative catalog. It carries no quantities, costs or order data.
 */
class IngredientStockChanged implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array<string, mixed> */
    private array $payload;

    public function __construct(string $branchId, string $reason)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'ingredients.changed',
            'branch_id' => $branchId,
            'reason' => $reason,
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('branch.'.$this->payload['branch_id'].'.inventory')];
    }

    public function broadcastAs(): string
    {
        return 'ingredients.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
