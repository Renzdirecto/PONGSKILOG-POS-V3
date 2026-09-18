<?php

namespace App\Support;

use App\Enums\StoreSessionStatus;
use App\Models\Branch;

class StoreState
{
    /** Resolve persisted state for a branch already authorized by the caller. */
    public function status(Branch $branch): StoreSessionStatus
    {
        return $branch->storeSessions()->where('status', StoreSessionStatus::Open)->exists()
            ? StoreSessionStatus::Open
            : StoreSessionStatus::Closed;
    }
}
