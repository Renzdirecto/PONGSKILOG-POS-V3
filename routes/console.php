<?php

use App\Models\CustomerScreen;
use App\Models\OrderPickupToken;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('qr:archive-stale')->everyMinute()->withoutOverlapping();

/** Phase 19.6: expired pickup links (with their customer push endpoint) and abandoned unpaired customer screens. */
Schedule::command('model:prune', ['--model' => [CustomerScreen::class, OrderPickupToken::class]])->daily()->withoutOverlapping();
