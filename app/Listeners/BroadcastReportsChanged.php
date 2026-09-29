<?php

namespace App\Listeners;

use App\Events\KitchenStatusChanged;
use App\Events\OrderCommitted;
use App\Events\OrderUpdated;
use App\Events\OrderVoided;
use App\Events\ReportsChanged;
use App\Events\StoreClosed;
use App\Events\StoreExpenseRecorded;

/**
 * Turns every committed operational change that moves business figures (a committed, edited, settled or voided Order,
 * a kitchen status, a Store expense or a Store close) into the business-wide `reports.changed` invalidation signal.
 * The source events already dispatch after their transaction commits.
 */
class BroadcastReportsChanged
{
    public function handle(OrderCommitted|OrderUpdated|OrderVoided|KitchenStatusChanged|StoreExpenseRecorded|StoreClosed $event): void
    {
        $branchId = $event->broadcastWith()['branch_id'] ?? null;
        if (! is_string($branchId) || $branchId === '') {
            return;
        }

        ReportsChanged::dispatch($branchId, $event->broadcastAs());
    }
}
