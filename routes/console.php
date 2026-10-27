<?php

use Illuminate\Support\Facades\Schedule;

// New regular elections appear in the TSE index.
Schedule::command('tse:descobrir')
    ->hourly()
    ->withoutOverlapping();

// Elections being counted: only on the election day (after the polls close)
// and the following days until every result is final.
Schedule::command('tse:sincronizar')
    ->everyThirtySeconds()
    ->withoutOverlapping(10)
    ->runInBackground();

// Past elections not imported yet, while the TSE still publishes them. A
// large one takes long, and is resumed after a 429.
Schedule::command('tse:importar')
    ->hourly()
    ->withoutOverlapping(360)
    ->runInBackground();

// Push notifications are queued by the sync; a short-lived worker started by
// the scheduler sends them, so no permanent worker process is required.
Schedule::command('queue:work --stop-when-empty --max-time=60')
    ->everyFifteenSeconds()
    ->withoutOverlapping()
    ->runInBackground();
