<?php

namespace App\Jobs;

use App\Support\HealthChecks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Dispatched by the scheduler every minute and handled by a queue worker: its timestamp proves that both the scheduler
 * and at least one worker are alive (Web Push, Kitchen/Order Ready pushes and pickup Buzz all need the worker).
 */
class RecordQueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Cache::put(HealthChecks::QUEUE_HEARTBEAT_KEY, now()->getTimestamp(), now()->addMinutes(30));
    }
}
