<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('tse:sincronizar')
    ->everyThirtySeconds()
    ->withoutOverlapping(10)
    ->runInBackground();

// Push notifications are queued by the sync; a short-lived worker started by
// the scheduler sends them, so no permanent worker process is required.
Schedule::command('queue:work --stop-when-empty --max-time=60')
    ->everyFifteenSeconds()
    ->withoutOverlapping()
    ->runInBackground();
