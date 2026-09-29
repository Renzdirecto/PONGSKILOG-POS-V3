<?php

namespace App\Events;

use App\Models\StoreSessionExpense;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

class StoreExpenseRecorded implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    /** @var array<string, string|bool> */
    private array $payload;

    /**
     * Create a new event instance.
     */
    public function __construct(StoreSessionExpense $expense, bool $inventoryLinked)
    {
        $this->payload = [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'store.expense_recorded',
            'branch_id' => $expense->branch_id,
            'expense_id' => $expense->id,
            'store_session_id' => $expense->store_session_id,
            'payment_source' => $expense->payment_source,
            'amount' => $expense->amount,
            'inventory_linked' => $inventoryLinked,
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
        return [new PrivateChannel('branch.'.$this->payload['branch_id'].'.store-session')];
    }

    public function broadcastAs(): string
    {
        return 'store.expense_recorded';
    }

    /** @return array<string, string|bool> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
