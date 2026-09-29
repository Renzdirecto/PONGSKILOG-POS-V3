<?php

use App\Jobs\RecordQueueHeartbeat;
use App\Models\CustomerScreen;
use App\Models\OrderPickupToken;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/** A crashed run releases its overlap lock after 10 minutes (not the 24-hour default); one server runs it. */
Schedule::command('qr:archive-stale')->everyMinute()->withoutOverlapping(10)->onOneServer();

/** Phase 19.6: expired pickup links (with their customer push endpoint) and abandoned unpaired customer screens. */
Schedule::command('model:prune', ['--model' => [CustomerScreen::class, OrderPickupToken::class]])->daily()->withoutOverlapping(60)->onOneServer();

/** Phase 20: a worker handling this proves the scheduler and the queue are alive (read by GET /health). */
Schedule::job(new RecordQueueHeartbeat)->everyMinute()->name('queue-heartbeat')->onOneServer();
